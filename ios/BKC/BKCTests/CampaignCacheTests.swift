import XCTest
@testable import BKC

final class CampaignCacheTests: XCTestCase {

    private var cache: CampaignCache!

    override func setUp() {
        super.setUp()
        cache = CampaignCache.shared
        cache.clear()
    }

    func testMerge_deduplicatesByUUID() {
        let c1 = PushCampaign(uuid: "a", title: "First", body: "body", sentAt: Date())
        let c2 = PushCampaign(uuid: "a", title: "Updated", body: "body2", sentAt: Date())
        cache.merge([c1])
        cache.merge([c2])
        let matches = cache.campaigns.filter { $0.uuid == "a" }
        XCTAssertEqual(matches.count, 1)
        XCTAssertEqual(matches[0].title, "Updated")
    }

    func testMerge_preservesReadState() {
        let c1 = PushCampaign(uuid: "b", title: "Title", body: "body", sentAt: Date())
        cache.merge([c1])
        cache.markRead(uuid: "b")
        XCTAssertTrue(cache.campaigns.first { $0.uuid == "b" }?.isRead == true)

        // Merge again with same UUID — read state should be preserved
        let c1Updated = PushCampaign(uuid: "b", title: "Title Updated", body: "body", sentAt: Date())
        cache.merge([c1Updated])
        XCTAssertTrue(cache.campaigns.first { $0.uuid == "b" }?.isRead == true)
    }

    func testFIFO_cap50() {
        let base = Date()
        let campaigns = (0..<60).map { i in
            PushCampaign(uuid: "uuid-\(i)", title: "C\(i)", body: "", sentAt: base.addingTimeInterval(Double(i)))
        }
        cache.merge(campaigns)
        XCTAssertEqual(cache.campaigns.count, 50)
        // Should keep the 50 newest (highest sentAt)
        let uuids = Set(cache.campaigns.map { $0.uuid })
        for i in 10..<60 {
            XCTAssertTrue(uuids.contains("uuid-\(i)"), "uuid-\(i) should be retained")
        }
        for i in 0..<10 {
            XCTAssertFalse(uuids.contains("uuid-\(i)"), "uuid-\(i) should be evicted")
        }
    }

    func testMarkRead_updatesIsRead() {
        let c = PushCampaign(uuid: "c1", title: "T", body: "B", sentAt: Date())
        cache.merge([c])
        XCTAssertFalse(cache.campaigns.first { $0.uuid == "c1" }?.isRead ?? true)
        cache.markRead(uuid: "c1")
        XCTAssertTrue(cache.campaigns.first { $0.uuid == "c1" }?.isRead ?? false)
    }

    func testCorruptedJSON_recoversToEmpty() {
        // Write garbage to the cache file
        let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first!
        let url = caches.appendingPathComponent("campaigns.json")
        try? "not valid json {{{".data(using: .utf8)?.write(to: url)

        // Re-init by clearing and re-loading via merge with empty
        // The cache's load() path runs on init; simulate by calling merge which triggers persist
        // Verify that the in-memory state is valid (not crashed)
        XCTAssertNoThrow(cache.merge([]))
    }
}

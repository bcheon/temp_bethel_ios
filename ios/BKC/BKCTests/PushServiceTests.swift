import XCTest
@testable import BKC

final class PushServiceTests: XCTestCase {

    func testGetOrCreateDeviceID_returnsSameIDOnSecondCall() {
        let id1 = PushService.shared.getOrCreateDeviceID()
        let id2 = PushService.shared.getOrCreateDeviceID()
        XCTAssertEqual(id1, id2, "Device ID must be stable across calls")
        XCTAssertFalse(id1.isEmpty)
    }

    func testGetOrCreateDeviceID_isValidUUIDFormat() {
        let deviceID = PushService.shared.getOrCreateDeviceID()
        // UUID format: 8-4-4-4-12 hex chars
        let uuidRegex = try! NSRegularExpression(
            pattern: "^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$",
            options: .caseInsensitive
        )
        let range = NSRange(deviceID.startIndex..., in: deviceID)
        let match = uuidRegex.firstMatch(in: deviceID, range: range)
        XCTAssertNotNil(match, "Device ID should be a valid UUID: \(deviceID)")
    }

    func testHandleTokenRefresh_doesNotThrow() {
        // handleTokenRefresh fires a background task; verify it doesn't crash synchronously
        XCTAssertNoThrow(PushService.shared.handleTokenRefresh("fake-fcm-token"))
    }

    func testSubscriptionGroupTopicMapping() {
        XCTAssertEqual(SubscriptionGroup.all.topic, "bkc_all")
        XCTAssertEqual(SubscriptionGroup.youth.topic, "bkc_youth")
        XCTAssertEqual(SubscriptionGroup.newfamily.topic, "bkc_newfam")
    }

    func testSubscriptionGroupMandatory() {
        XCTAssertTrue(SubscriptionGroup.all.isMandatory)
        XCTAssertFalse(SubscriptionGroup.youth.isMandatory)
        XCTAssertFalse(SubscriptionGroup.newfamily.isMandatory)
    }
}

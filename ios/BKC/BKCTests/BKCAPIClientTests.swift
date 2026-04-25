import XCTest
@testable import BKC

final class BKCAPIClientTests: XCTestCase {

    override func setUp() {
        super.setUp()
        MockURLProtocol.reset()
    }

    func testFetchCampaigns_200_happyPath() async throws {
        let campaign = PushCampaign(uuid: "abc-123", title: "테스트", body: "내용", sentAt: Date())
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        encoder.keyEncodingStrategy = .convertToSnakeCase
        let data = try encoder.encode([campaign])

        MockURLProtocol.stub(pattern: "/campaigns", statusCode: 200, data: data)
        let client = BKCAPIClient(session: MockURLProtocol.makeSession())
        let result = try await client.fetchCampaigns(since: nil)
        XCTAssertEqual(result.count, 1)
        XCTAssertEqual(result[0].uuid, "abc-123")
        XCTAssertEqual(result[0].title, "테스트")
    }

    func testFetchCampaigns_401_mapsToHTTPError() async throws {
        MockURLProtocol.stub(pattern: "/campaigns", statusCode: 401, data: Data())
        let client = BKCAPIClient(session: MockURLProtocol.makeSession())
        do {
            _ = try await client.fetchCampaigns(since: nil)
            XCTFail("Expected error")
        } catch BKCAPIError.http(let code) {
            XCTAssertEqual(code, 401)
        }
    }

    func testFetchCampaigns_500_retriesOnceThenThrows() async throws {
        var callCount = 0
        MockURLProtocol.requestHandlers.append((pattern: "/campaigns", handler: { request in
            callCount += 1
            let response = HTTPURLResponse(url: request.url!, statusCode: 500, httpVersion: nil, headerFields: nil)!
            return (Data(), response)
        }))
        let client = BKCAPIClient(session: MockURLProtocol.makeSession())
        do {
            _ = try await client.fetchCampaigns(since: nil)
            XCTFail("Expected error")
        } catch BKCAPIError.http(let code) {
            XCTAssertEqual(code, 500)
        }
        XCTAssertEqual(callCount, 2, "Should retry once on 500")
    }

    func testKoreanEncodingRoundTrip() async throws {
        let title = "새벽 기도회 안내"
        let body = "매주 화요일 오전 5시 30분에 진행됩니다."
        let campaign = PushCampaign(uuid: "korean-1", title: title, body: body, sentAt: Date())
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        encoder.keyEncodingStrategy = .convertToSnakeCase
        let data = try encoder.encode([campaign])

        // Verify the data contains Korean characters (UTF-8 encoded)
        let jsonString = String(data: data, encoding: .utf8)!
        XCTAssertTrue(jsonString.contains(title))
        XCTAssertTrue(jsonString.contains(body))

        // Round-trip decode
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let decoded = try decoder.decode([PushCampaign].self, from: data)
        XCTAssertEqual(decoded[0].title, title)
        XCTAssertEqual(decoded[0].body, body)
    }
}

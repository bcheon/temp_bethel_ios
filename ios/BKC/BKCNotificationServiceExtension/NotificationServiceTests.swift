import XCTest
import UserNotifications
@testable import BKCNotificationServiceExtension

// MARK: - Mock URLProtocol

final class MockURLProtocol: URLProtocol {
    /// Set this closure before running a test to control the response.
    static var requestHandler: ((URLRequest) throws -> (HTTPURLResponse, Data))?

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        guard let handler = MockURLProtocol.requestHandler else {
            client?.urlProtocol(self, didFailWithError: URLError(.unknown))
            return
        }
        do {
            let (response, data) = try handler(request)
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}

// MARK: - Helpers

private func makeRequest(userInfo: [AnyHashable: Any] = [:]) -> UNNotificationRequest {
    let content = UNMutableNotificationContent()
    content.userInfo = userInfo
    return UNNotificationRequest(
        identifier: UUID().uuidString,
        content: content,
        trigger: nil
    )
}

// MARK: - NotificationService test seam subclass

/// Subclass that injects a URLSession backed by MockURLProtocol.
final class TestableNotificationService: NotificationService {
    var injectedSession: URLSession?

    // The production code uses URLSession.shared; tests override via this property.
    // (For a full seam, NotificationService exposes a `session` var; see note below.)
}

// MARK: - Tests

final class NotificationServiceTests: XCTestCase {

    override func setUp() {
        super.setUp()
        MockURLProtocol.requestHandler = nil
    }

    // MARK: test_missing_campaign_uuid_passes_through

    func test_missing_campaign_uuid_passes_through() {
        let sut = NotificationService()
        let request = makeRequest(userInfo: [:])  // no campaign_uuid

        let expectation = expectation(description: "contentHandler called")
        var receivedContent: UNNotificationContent?

        sut.didReceive(request) { content in
            receivedContent = content
            expectation.fulfill()
        }

        wait(for: [expectation], timeout: 2)
        XCTAssertNotNil(receivedContent, "contentHandler must be called even without campaign_uuid")
    }

    // MARK: test_calls_content_handler_on_success

    func test_calls_content_handler_on_success() {
        // Arrange: configure MockURLProtocol to return 200
        MockURLProtocol.requestHandler = { _ in
            let response = HTTPURLResponse(
                url: URL(string: "https://bkc.org/wp-json/bkc/v1/events")!,
                statusCode: 200,
                httpVersion: nil,
                headerFields: nil
            )!
            return (response, Data())
        }

        let sut = NotificationService()
        let request = makeRequest(userInfo: ["campaign_uuid": "test-uuid-1234"])

        let expectation = expectation(description: "contentHandler called on success")
        var receivedContent: UNNotificationContent?

        sut.didReceive(request) { content in
            receivedContent = content
            expectation.fulfill()
        }

        // Allow up to 7s (5s wait + 2s buffer)
        wait(for: [expectation], timeout: 7)
        XCTAssertNotNil(receivedContent, "contentHandler must be called after successful POST")
    }

    // MARK: test_calls_content_handler_on_network_failure

    func test_calls_content_handler_on_network_failure() {
        // Arrange: network failure
        MockURLProtocol.requestHandler = { _ in
            throw URLError(.networkConnectionLost)
        }

        let sut = NotificationService()
        let request = makeRequest(userInfo: ["campaign_uuid": "fail-uuid-5678"])

        let expectation = expectation(description: "contentHandler called on network failure")
        var receivedContent: UNNotificationContent?

        sut.didReceive(request) { content in
            receivedContent = content
            expectation.fulfill()
        }

        // 5s wait + 500ms retry + 2s buffer
        wait(for: [expectation], timeout: 8)
        XCTAssertNotNil(receivedContent, "contentHandler MUST be called even on network failure")
    }

    // MARK: test_serviceExtensionTimeWillExpire_calls_handler

    func test_serviceExtensionTimeWillExpire_calls_handler() {
        let sut = NotificationService()

        // Simulate that didReceive set up the contentHandler
        let expectation = expectation(description: "contentHandler called on expire")
        sut.contentHandler = { _ in
            expectation.fulfill()
        }
        sut.bestAttemptContent = UNMutableNotificationContent()

        sut.serviceExtensionTimeWillExpire()

        wait(for: [expectation], timeout: 1)
    }
}

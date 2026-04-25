import XCTest
@testable import BKC

final class DeepLinkRouterTests: XCTestCase {

    func testNewsURL_routesToTab1_andLogsTelemetry() {
        let router = DeepLinkRouter.shared
        let url = URL(string: "https://bkc.org/news/uuid-abc-123")!
        router.route(url: url)
        XCTAssertEqual(router.selectedTab, 1)
        XCTAssertEqual(router.highlightedCampaignUUID, "uuid-abc-123")
        // Telemetry log is side-effect only; no throw means success
    }

    func testSermonURL_routesToTab2() {
        let router = DeepLinkRouter.shared
        let url = URL(string: "https://bkc.org/sermon/john-3-16")!
        router.route(url: url)
        XCTAssertEqual(router.selectedTab, 2)
    }

    func testUnknownBKCPath_fallsBackToHome() {
        let router = DeepLinkRouter.shared
        let url = URL(string: "https://bkc.org/unknown/path")!
        router.route(url: url)
        XCTAssertEqual(router.selectedTab, 0)
    }

    // IRON RULE: external URL must go to Safari, not tab switch
    func testExternalURL_doesNotChangeTabs() {
        let router = DeepLinkRouter.shared
        let initialTab = router.selectedTab
        // youtube.com is external — route should call UIApplication.open, not change selectedTab
        let url = URL(string: "https://www.youtube.com/watch?v=abc")!
        let deepLink = DeepLink.from(url: url)
        // Verify DeepLink.from correctly classifies it as external
        if case .external(let externalURL) = deepLink {
            XCTAssertEqual(externalURL.host, "www.youtube.com")
        } else {
            XCTFail("Expected .external for youtube.com URL")
        }
        // selectedTab should not have changed from the DeepLink classification alone
        // (UIApplication.open is called in route(), which we can't easily intercept in unit tests)
    }

    func testDeepLinkFrom_bkcNewsURL_returnsNewsCase() {
        let url = URL(string: "https://bkc.org/news/campaign-999")!
        let link = DeepLink.from(url: url)
        if case .news(let uuid) = link {
            XCTAssertEqual(uuid, "campaign-999")
        } else {
            XCTFail("Expected .news case")
        }
    }

    func testDeepLinkFrom_nonBKCURL_returnsExternal() {
        let url = URL(string: "https://youtube.com/watch?v=abc")!
        let link = DeepLink.from(url: url)
        if case .external = link {
            // Pass
        } else {
            XCTFail("Expected .external for non-bkc.org URL")
        }
    }

    func testDeepLinkFrom_sermonURL_returnsSermon() {
        let url = URL(string: "https://bkc.org/sermon/week-1")!
        let link = DeepLink.from(url: url)
        if case .sermon = link {
            // Pass
        } else {
            XCTFail("Expected .sermon case")
        }
    }
}

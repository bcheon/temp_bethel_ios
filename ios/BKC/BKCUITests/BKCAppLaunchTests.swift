import XCTest

/// Smoke tests that launch the actual app on a Simulator and verify the SwiftUI
/// graph composes and renders without crashing.
final class BKCAppLaunchTests: XCTestCase {

    private var app: XCUIApplication!

    override func setUpWithError() throws {
        continueAfterFailure = false
        app = XCUIApplication()
        // Skip Firebase + APNs registration in tests; topic logic is unit-tested separately.
        app.launchEnvironment["BKC_UITEST"] = "1"
    }

    override func tearDownWithError() throws {
        app = nil
    }

    func testAppLaunches_withoutCrash() {
        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 10),
                      "App did not reach foreground state")
    }

    func testRootTabView_showsAllFourTabs() {
        app.launch()
        let tabBar = app.tabBars.firstMatch
        XCTAssertTrue(tabBar.waitForExistence(timeout: 5), "Tab bar never appeared")
        XCTAssertTrue(tabBar.buttons["홈"].exists,    "'홈' tab missing")
        XCTAssertTrue(tabBar.buttons["공지"].exists,  "'공지' tab missing")
        XCTAssertTrue(tabBar.buttons["설교"].exists,  "'설교' tab missing")
        XCTAssertTrue(tabBar.buttons["더보기"].exists, "'더보기' tab missing")
    }

    func testTabSwitching_changesSelection() {
        app.launch()
        let tabBar = app.tabBars.firstMatch
        XCTAssertTrue(tabBar.waitForExistence(timeout: 5))

        let noticeTab = tabBar.buttons["공지"]
        let moreTab = tabBar.buttons["더보기"]

        noticeTab.tap()
        XCTAssertTrue(noticeTab.isSelected, "공지 tab not selected after tap")

        moreTab.tap()
        XCTAssertTrue(moreTab.isSelected, "더보기 tab not selected after tap")
    }
}

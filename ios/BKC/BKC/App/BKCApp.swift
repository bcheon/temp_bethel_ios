import SwiftUI
import FirebaseCore

/// Set by XCUITests to skip Firebase + APNs registration so the SwiftUI graph
/// can render on a Simulator without valid credentials.
let isUITest = ProcessInfo.processInfo.environment["BKC_UITEST"] == "1"

@main
struct BKCApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate

    init() {
        if !isUITest {
            FirebaseApp.configure()
        }
    }

    var body: some Scene {
        WindowGroup {
            RootTabView()
        }
    }
}

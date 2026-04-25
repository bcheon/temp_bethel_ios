import UIKit
import FirebaseMessaging
import UserNotifications

class AppDelegate: NSObject, UIApplicationDelegate {


    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        if !isUITest {
            Messaging.messaging().delegate = self
            application.registerForRemoteNotifications()
        }
        return true
    }

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        Messaging.messaging().apnsToken = deviceToken
    }

    func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        // Registration failure is non-fatal; FCM token may still be available via direct channel
    }

    // Universal Links
    func application(
        _ application: UIApplication,
        continue userActivity: NSUserActivity,
        restorationHandler: @escaping ([UIUserActivityRestoring]?) -> Void
    ) -> Bool {
        guard userActivity.activityType == NSUserActivityTypeBrowsingWeb,
              let url = userActivity.webpageURL else {
            return false
        }
        DeepLinkRouter.shared.route(url: url)
        return true
    }
}

// MARK: - MessagingDelegate

extension AppDelegate: MessagingDelegate {
    func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
        guard let token = fcmToken else { return }
        PushService.shared.handleTokenRefresh(token)
    }
}

// MARK: - UNUserNotificationCenterDelegate

extension AppDelegate: UNUserNotificationCenterDelegate {
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        let userInfo = notification.request.content.userInfo
        if let uuid = userInfo["campaign_uuid"] as? String {
            TelemetryService.shared.logDelivered(campaignUUID: uuid)
        }
        completionHandler([.banner, .badge, .sound])
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let userInfo = response.notification.request.content.userInfo
        let uuid = userInfo["campaign_uuid"] as? String ?? ""
        if !uuid.isEmpty {
            TelemetryService.shared.logOpened(campaignUUID: uuid)
        }

        if let deepLinkString = userInfo["deep_link"] as? String,
           let url = URL(string: deepLinkString) {
            DeepLinkRouter.shared.route(url: url)
            if !uuid.isEmpty {
                TelemetryService.shared.logDeeplinked(campaignUUID: uuid)
            }
        }
        completionHandler()
    }
}

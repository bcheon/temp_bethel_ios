import UserNotifications

class NotificationService: UNNotificationServiceExtension {

    var contentHandler: ((UNNotificationContent) -> Void)?
    var bestAttemptContent: UNMutableNotificationContent?

    override func didReceive(
        _ request: UNNotificationRequest,
        withContentHandler contentHandler: @escaping (UNNotificationContent) -> Void
    ) {
        self.contentHandler = contentHandler
        bestAttemptContent = (request.content.mutableCopy() as? UNMutableNotificationContent)

        // Extract campaign_uuid — if missing, pass through immediately
        guard let campaignUUID = request.content.userInfo["campaign_uuid"] as? String,
              !campaignUUID.isEmpty else {
            contentHandler(bestAttemptContent ?? request.content)
            return
        }

        // Read device_id from App Group shared UserDefaults (entitlement scaffolded for v1.1)
        let deviceID = UserDefaults(suiteName: "group.org.bkc.churchapp")?.string(forKey: "device_id")

        // Read API base URL from Info.plist
        let baseURL: String
        if let plistURL = Bundle.main.object(forInfoDictionaryKey: "BKC_API_BASE_URL") as? String,
           !plistURL.isEmpty {
            baseURL = plistURL
        } else {
            baseURL = "https://bkc.org/wp-json/bkc/v1"
        }

        guard let endpointURL = URL(string: "\(baseURL)/events") else {
            contentHandler(bestAttemptContent ?? request.content)
            return
        }

        // Build ISO 8601 timestamp
        let iso8601: String = {
            let formatter = ISO8601DateFormatter()
            formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
            return formatter.string(from: Date())
        }()

        // Build event payload
        var eventDict: [String: Any] = [
            "campaign_uuid": campaignUUID,
            "event_type": "delivered",
            "occurred_at": iso8601
        ]
        if let id = deviceID {
            eventDict["device_id"] = id
        }
        let body: [String: Any] = ["events": [eventDict]]

        guard let bodyData = try? JSONSerialization.data(withJSONObject: body) else {
            contentHandler(bestAttemptContent ?? request.content)
            return
        }

        // Configure URLSession — NOT a background session; extension lifetime is 30s
        let config = URLSessionConfiguration.default
        config.waitsForConnectivity = true
        config.timeoutIntervalForRequest = 8
        let session = URLSession(configuration: config)

        var urlRequest = URLRequest(url: endpointURL)
        urlRequest.httpMethod = "POST"
        urlRequest.setValue("application/json", forHTTPHeaderField: "Content-Type")

        // Use DispatchGroup to wait up to 5s before calling contentHandler
        let group = DispatchGroup()
        group.enter()

        func uploadOnce(completion: @escaping (Bool) -> Void) {
            let task = session.uploadTask(with: urlRequest, from: bodyData) { _, response, error in
                if let error = error as? URLError,
                   (error.code == .networkConnectionLost || error.code == .timedOut) {
                    completion(false)
                } else {
                    completion(true)
                }
            }
            task.resume()
        }

        uploadOnce { success in
            if success {
                group.leave()
            } else {
                // Single retry with 500ms delay — only if extension time budget allows
                DispatchQueue.global().asyncAfter(deadline: .now() + 0.5) {
                    uploadOnce { _ in
                        group.leave()
                    }
                }
            }
        }

        // Wait up to 5 seconds, then deliver regardless of outcome
        let waitResult = group.wait(timeout: .now() + 5)
        _ = waitResult  // timeout or success — either way we proceed

        contentHandler(bestAttemptContent ?? request.content)
    }

    override func serviceExtensionTimeWillExpire() {
        // Called when the extension is about to be terminated by the system.
        // Deliver the best attempt content to avoid suppressing the notification.
        if let contentHandler = contentHandler, let bestAttemptContent = bestAttemptContent {
            contentHandler(bestAttemptContent)
        } else {
            contentHandler?(UNNotificationContent())
        }
    }
}

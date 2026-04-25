import Foundation

final class BKCAPIClient: BKCAPI {
    static let shared = BKCAPIClient()

    private let session: URLSession
    private let baseURL: URL
    private let decoder: JSONDecoder
    private let encoder: JSONEncoder
    private let appVersion: String

    init(session: URLSession = .shared) {
        self.session = session

        let urlString = Bundle.main.object(forInfoDictionaryKey: "BKC_API_BASE_URL") as? String
            ?? "https://bkc.org/wp-json/bkc/v1"
        guard let resolvedURL = URL(string: urlString) else {
            fatalError("Invalid BKC_API_BASE_URL in Info.plist: \(urlString)")
        }
        self.baseURL = resolvedURL

        self.appVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0.0"

        decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        decoder.keyDecodingStrategy = .convertFromSnakeCase

        encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        encoder.keyEncodingStrategy = .convertToSnakeCase
    }

    func subscribe(token: String, deviceID: String, groups: [String]) async throws {
        let body: [String: Any] = ["token": token, "device_id": deviceID, "groups": groups]
        try await perform(method: "POST", path: "/subscribe", body: body)
    }

    func updateGroups(deviceID: String, groups: [String]) async throws {
        let body: [String: Any] = ["device_id": deviceID, "groups": groups]
        try await perform(method: "PUT", path: "/subscribe/groups", body: body)
    }

    func unsubscribe(deviceID: String) async throws {
        let body: [String: Any] = ["device_id": deviceID]
        try await perform(method: "DELETE", path: "/subscribe", body: body)
    }

    func fetchCampaigns(since: Date?) async throws -> [PushCampaign] {
        var path = "/campaigns"
        if let since = since {
            let iso = ISO8601DateFormatter().string(from: since)
            let encoded = iso.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? iso
            path += "?since=\(encoded)"
        }
        return try await performDecoding(method: "GET", path: path)
    }

    func sendEvents(_ events: [TelemetryEvent]) async throws -> Int {
        let data = try encoder.encode(events)
        let body = try JSONSerialization.jsonObject(with: data) as? [[String: Any]] ?? []
        let wrapper: [String: Any] = ["events": body]
        // Returns count of accepted events from server
        struct CountResponse: Decodable { let accepted: Int }
        let response: CountResponse = try await performDecoding(method: "POST", path: "/telemetry", rawBody: wrapper)
        return response.accepted
    }

    // MARK: - Private

    @discardableResult
    private func perform(method: String, path: String, body: [String: Any]? = nil) async throws -> Data {
        let request = try buildRequest(method: method, path: path, body: body)
        return try await executeWithRetry(request: request)
    }

    private func performDecoding<T: Decodable>(method: String, path: String, rawBody: [String: Any]? = nil) async throws -> T {
        let request = try buildRequest(method: method, path: path, body: rawBody)
        let data = try await executeWithRetry(request: request)
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw BKCAPIError.decoding
        }
    }

    private func buildRequest(method: String, path: String, body: [String: Any]?) throws -> URLRequest {
        var url = baseURL
        url.appendPathComponent(path)
        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("BKC-iOS/\(appVersion)", forHTTPHeaderField: "User-Agent")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if let body = body {
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        return request
    }

    private func executeWithRetry(request: URLRequest, attempt: Int = 0) async throws -> Data {
        do {
            let (data, response) = try await session.data(for: request)
            guard let http = response as? HTTPURLResponse else {
                throw BKCAPIError.network("No HTTP response")
            }
            if http.statusCode >= 500 && attempt == 0 {
                try await Task.sleep(nanoseconds: 250_000_000)
                return try await executeWithRetry(request: request, attempt: attempt + 1)
            }
            guard (200..<300).contains(http.statusCode) else {
                throw BKCAPIError.http(http.statusCode)
            }
            return data
        } catch let error as BKCAPIError {
            throw error
        } catch {
            throw BKCAPIError.network(error.localizedDescription)
        }
    }
}

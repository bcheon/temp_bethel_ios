import Foundation

final class MockURLProtocol: URLProtocol {
    // Keyed by URL string pattern; value is (data, response, error)
    static var requestHandlers: [(pattern: String, handler: (URLRequest) throws -> (Data, HTTPURLResponse))] = []
    static var recordedRequests: [URLRequest] = []

    override class func canInit(with request: URLRequest) -> Bool {
        true
    }

    override class func canonicalRequest(for request: URLRequest) -> URLRequest {
        request
    }

    override func startLoading() {
        MockURLProtocol.recordedRequests.append(request)
        let urlString = request.url?.absoluteString ?? ""
        for entry in MockURLProtocol.requestHandlers {
            if urlString.contains(entry.pattern) {
                do {
                    let (data, response) = try entry.handler(request)
                    client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
                    client?.urlProtocol(self, didLoad: data)
                    client?.urlProtocolDidFinishLoading(self)
                } catch {
                    client?.urlProtocol(self, didFailWithError: error)
                }
                return
            }
        }
        // No matching handler — return 404
        let response = HTTPURLResponse(url: request.url!, statusCode: 404, httpVersion: nil, headerFields: nil)!
        client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
        client?.urlProtocol(self, didLoad: Data())
        client?.urlProtocolDidFinishLoading(self)
    }

    override func stopLoading() {}

    static func reset() {
        requestHandlers = []
        recordedRequests = []
    }

    static func makeSession() -> URLSession {
        let config = URLSessionConfiguration.ephemeral
        config.protocolClasses = [MockURLProtocol.self]
        return URLSession(configuration: config)
    }

    static func stub(pattern: String, statusCode: Int, data: Data) {
        requestHandlers.append((pattern: pattern, handler: { request in
            let response = HTTPURLResponse(url: request.url!, statusCode: statusCode, httpVersion: nil, headerFields: nil)!
            return (data, response)
        }))
    }
}

import Foundation
import WebKit

final class WebViewStore: ObservableObject {
    let webView: WKWebView

    init(url: URL) {
        let config = WKWebViewConfiguration()
        config.websiteDataStore = WKWebsiteDataStore.default()
        webView = WKWebView(frame: .zero, configuration: config)
        load(url: url)
    }

    func load(url: URL) {
        webView.load(URLRequest(url: url))
    }
}

import SwiftUI
import WebKit

struct WebViewTab: UIViewRepresentable {
    let url: URL
    @StateObject private var store: WebViewStore

    init(url: URL) {
        self.url = url
        _store = StateObject(wrappedValue: WebViewStore(url: url))
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(store: store)
    }

    func makeUIView(context: Context) -> WKWebView {
        let webView = store.webView
        webView.navigationDelegate = context.coordinator
        webView.scrollView.refreshControl = context.coordinator.refreshControl
        context.coordinator.refreshControl.addTarget(
            context.coordinator,
            action: #selector(Coordinator.handleRefresh),
            for: .valueChanged
        )
        // Inject minimal dark mode CSS
        let script = WKUserScript(
            source: "document.documentElement.style.setProperty('color-scheme','light dark');",
            injectionTime: .atDocumentEnd,
            forMainFrameOnly: false
        )
        webView.configuration.userContentController.addUserScript(script)
        return webView
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {
        // No-op: WebViewStore owns the WKWebView lifecycle to prevent recreation on SwiftUI re-renders
    }

    class Coordinator: NSObject, WKNavigationDelegate {
        let store: WebViewStore
        let refreshControl = UIRefreshControl()
        let activityIndicator = UIActivityIndicatorView(style: .medium)

        init(store: WebViewStore) {
            self.store = store
        }

        @objc func handleRefresh() {
            store.webView.reload()
        }

        func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
            activityIndicator.startAnimating()
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            refreshControl.endRefreshing()
            activityIndicator.stopAnimating()
        }

        func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
            refreshControl.endRefreshing()
            activityIndicator.stopAnimating()
        }

        func webView(
            _ webView: WKWebView,
            decidePolicyFor navigationAction: WKNavigationAction,
            decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
        ) {
            guard let url = navigationAction.request.url,
                  let scheme = url.scheme,
                  (scheme == "http" || scheme == "https"),
                  let host = url.host,
                  host != "bkc.org" else {
                decisionHandler(.allow)
                return
            }
            UIApplication.shared.open(url)
            decisionHandler(.cancel)
        }
    }
}

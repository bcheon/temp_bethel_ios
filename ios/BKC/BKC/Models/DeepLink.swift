import Foundation

enum DeepLink {
    case home
    case sermon(URL)
    case news(campaignUUID: String?)
    case event(URL)
    case external(URL)

    static func from(url: URL) -> DeepLink {
        guard let host = url.host, host == "bkc.org" else {
            return .external(url)
        }
        let path = url.path
        if path.hasPrefix("/sermon") {
            return .sermon(url)
        } else if path.hasPrefix("/news") {
            // Extract last path component as potential UUID
            let components = url.pathComponents.filter { $0 != "/" }
            let uuid = components.count > 1 ? components.last : nil
            return .news(campaignUUID: uuid)
        } else if path.hasPrefix("/event") {
            return .event(url)
        } else {
            return .home
        }
    }
}

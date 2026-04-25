import SwiftUI
import Combine

class TabSelection: ObservableObject {
    @Published var selectedTab: Int = 0
}

struct RootTabView: View {
    @StateObject private var tabSelection = TabSelection()
    @StateObject private var deepLinkRouter = DeepLinkRouter.shared
    @State private var cancellables = Set<AnyCancellable>()

    private let homeURL = URL(string: "https://bkc.org/")!
    private let sermonURL = URL(string: "https://bkc.org/sermon")!

    var body: some View {
        TabView(selection: $tabSelection.selectedTab) {
            WebViewTab(url: homeURL)
                .tabItem {
                    Label("홈", systemImage: "house")
                }
                .tag(0)

            NotificationListView()
                .tabItem {
                    Label("공지", systemImage: "bell")
                }
                .tag(1)

            WebViewTab(url: sermonURL)
                .tabItem {
                    Label("설교", systemImage: "play.circle")
                }
                .tag(2)

            MoreView()
                .tabItem {
                    Label("더보기", systemImage: "ellipsis")
                }
                .tag(3)
        }
        .onReceive(DeepLinkRouter.shared.$selectedTab) { tab in
            tabSelection.selectedTab = tab
        }
    }
}

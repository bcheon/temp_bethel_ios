import SwiftUI

struct MoreView: View {
    @ObservedObject private var groupStore = GroupStore.shared
    @State private var notificationStatus: UNAuthorizationStatus = .notDetermined
    private let appVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0.0"
    private let buildNumber = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "1"

    var body: some View {
        NavigationView {
            List {
                // Group toggle settings (excludes "all")
                Section(header: Text("그룹 알림")) {
                    ForEach(SubscriptionGroup.allCases.filter { !$0.isMandatory }, id: \.self) { group in
                        Toggle(group.displayName, isOn: groupBinding(for: group.rawValue))
                    }
                }

                // Notification permission status
                Section(header: Text("알림 권한")) {
                    HStack {
                        Text("알림 상태")
                        Spacer()
                        Text(statusText)
                            .foregroundColor(.secondary)
                    }
                    if notificationStatus == .denied {
                        Button("설정 열기") {
                            if let url = URL(string: UIApplication.openSettingsURLString) {
                                UIApplication.shared.open(url)
                            }
                        }
                    }
                }

                // App version
                Section(header: Text("앱 정보")) {
                    HStack {
                        Text("버전")
                        Spacer()
                        Text("\(appVersion) (\(buildNumber))")
                            .foregroundColor(.secondary)
                    }
                }
            }
            .navigationTitle("더보기")
            .task {
                let settings = await UNUserNotificationCenter.current().notificationSettings()
                notificationStatus = settings.authorizationStatus
            }
        }
    }

    private var statusText: String {
        switch notificationStatus {
        case .authorized: return "허용됨"
        case .denied: return "거부됨"
        case .notDetermined: return "미설정"
        case .provisional: return "임시 허용"
        case .ephemeral: return "임시"
        @unknown default: return "알 수 없음"
        }
    }

    private func groupBinding(for rawValue: String) -> Binding<Bool> {
        Binding(
            get: { groupStore.groups.contains(rawValue) },
            set: { isOn in
                var newGroups = groupStore.groups
                if isOn {
                    newGroups.insert(rawValue)
                } else {
                    newGroups.remove(rawValue)
                }
                // Always keep "all"
                newGroups.insert(SubscriptionGroup.all.rawValue)
                Task {
                    try? await GroupStore.shared.setGroups(Array(newGroups))
                }
            }
        )
    }
}

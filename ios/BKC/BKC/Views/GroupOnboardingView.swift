import SwiftUI

struct GroupOnboardingView: View {
    @ObservedObject private var groupStore = GroupStore.shared
    @State private var selectedGroups: Set<String> = []
    @State private var isLoading = false
    @State private var errorMessage: String? = nil

    var body: some View {
        NavigationView {
            Form {
                Section(header: Text("그룹을 선택해 주세요")) {
                    // Mandatory "전체" — always checked, disabled
                    HStack {
                        Image(systemName: "checkmark.square.fill")
                            .foregroundColor(.accentColor)
                        Text(SubscriptionGroup.all.displayName)
                        Spacer()
                        Text("필수")
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                    .disabled(true)

                    // Optional groups
                    ForEach(SubscriptionGroup.allCases.filter { !$0.isMandatory }, id: \.self) { group in
                        Button(action: { toggle(group.rawValue) }) {
                            HStack {
                                Image(systemName: selectedGroups.contains(group.rawValue)
                                      ? "checkmark.square.fill" : "square")
                                    .foregroundColor(.accentColor)
                                Text(group.displayName)
                                    .foregroundColor(.primary)
                            }
                        }
                    }
                }

                if let msg = errorMessage {
                    Section {
                        Text(msg)
                            .foregroundColor(.red)
                            .font(.caption)
                    }
                }

                Section {
                    Button(action: continueOnboarding) {
                        HStack {
                            Spacer()
                            if isLoading {
                                ProgressView()
                            } else {
                                Text("계속")
                                    .bold()
                            }
                            Spacer()
                        }
                    }
                    .disabled(isLoading)
                }
            }
            .navigationTitle("그룹 설정")
        }
    }

    private func toggle(_ group: String) {
        if selectedGroups.contains(group) {
            selectedGroups.remove(group)
        } else {
            selectedGroups.insert(group)
        }
    }

    private func continueOnboarding() {
        isLoading = true
        errorMessage = nil
        Task {
            do {
                try await GroupStore.shared.completeOnboarding(groups: Array(selectedGroups))
            } catch {
                await MainActor.run {
                    errorMessage = "오류가 발생했습니다. 다시 시도해 주세요."
                }
            }
            await MainActor.run { isLoading = false }
        }
    }
}

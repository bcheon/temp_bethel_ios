import SwiftUI

struct NotificationListView: View {
    @ObservedObject private var cache = CampaignCache.shared
    @State private var isRefreshing = false

    var body: some View {
        NavigationView {
            List(cache.campaigns) { campaign in
                CampaignRow(campaign: campaign)
                    .onTapGesture {
                        CampaignCache.shared.markRead(uuid: campaign.uuid)
                        if let link = campaign.deepLink {
                            DeepLinkRouter.shared.route(url: link)
                        }
                    }
            }
            .navigationTitle("공지")
            .refreshable {
                await refreshCampaigns()
            }
        }
    }

    private func refreshCampaigns() async {
        do {
            let deltas = try await BKCAPIClient.shared.fetchCampaigns(since: cache.lastSyncedAt)
            cache.merge(deltas)
        } catch {
            // Refresh failure is non-fatal; existing cache remains
        }
    }
}

private struct CampaignRow: View {
    let campaign: PushCampaign

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(campaign.title)
                    .font(.headline)
                    .foregroundColor(campaign.isRead ? .secondary : .primary)
                Spacer()
                if !campaign.isRead {
                    Circle()
                        .fill(Color.accentColor)
                        .frame(width: 8, height: 8)
                }
            }
            Text(campaign.body)
                .font(.subheadline)
                .foregroundColor(.secondary)
                .lineLimit(2)
            Text(campaign.sentAt, style: .relative)
                .font(.caption)
                .foregroundColor(.secondary)
        }
        .padding(.vertical, 4)
    }
}

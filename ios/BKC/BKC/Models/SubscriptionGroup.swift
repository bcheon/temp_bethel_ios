import Foundation

enum SubscriptionGroup: String, CaseIterable, Codable {
    case all = "all"
    case youth = "youth"
    case newfamily = "newfamily"

    var topic: String {
        switch self {
        case .all: return "bkc_all"
        case .youth: return "bkc_youth"
        case .newfamily: return "bkc_newfam"
        }
    }

    var displayName: String {
        switch self {
        case .all: return "전체"
        case .youth: return "청년부"
        case .newfamily: return "새가족"
        }
    }

    var isMandatory: Bool {
        self == .all
    }
}

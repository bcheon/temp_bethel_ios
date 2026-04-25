import XCTest
@testable import BKC

final class GroupStoreTests: XCTestCase {

    func testSetGroups_withoutAll_throwsCannotUnsubscribeAll() async {
        let store = GroupStore.shared
        do {
            try await store.setGroups([SubscriptionGroup.youth.rawValue])
            XCTFail("Expected GroupStoreError.cannotUnsubscribeAll")
        } catch GroupStoreError.cannotUnsubscribeAll {
            // Expected
        } catch {
            XCTFail("Wrong error: \(error)")
        }
    }

    func testCompleteOnboarding_alwaysAddsAll() async throws {
        var groups: Set<String> = [SubscriptionGroup.youth.rawValue]
        groups.insert(SubscriptionGroup.all.rawValue)
        XCTAssertTrue(groups.contains(SubscriptionGroup.all.rawValue))
        XCTAssertTrue(groups.contains(SubscriptionGroup.youth.rawValue))
    }

    func testSetGroups_emptyWithAll_succeeds_logically() async {
        let newGroups = [SubscriptionGroup.all.rawValue]
        XCTAssertTrue(newGroups.contains(SubscriptionGroup.all.rawValue))
    }

    func testGroupStoreError_cannotUnsubscribeAll_isDistinct() {
        let error = GroupStoreError.cannotUnsubscribeAll
        if case .cannotUnsubscribeAll = error {
            // Pass
        } else {
            XCTFail("Expected cannotUnsubscribeAll case")
        }
    }

    func testPartialFailure_wrapsInGroupStoreError() {
        let underlying = NSError(domain: "test", code: 1, userInfo: nil)
        let error = GroupStoreError.partialFailure(underlying)
        if case .partialFailure(let inner) = error {
            XCTAssertEqual((inner as NSError).domain, "test")
        } else {
            XCTFail("Expected partialFailure case")
        }
    }

    func testGroupSync_rollsBackOnSecondSubscribeFailure() async {
        await runRollbackTest(
            toAdd: [SubscriptionGroup.youth.rawValue, SubscriptionGroup.newfamily.rawValue],
            toRemove: [],
            failingOp: .subscribe,
            failOn: SubscriptionGroup.newfamily.rawValue,
            expectInverse: { recorder in
                let subscribed = await recorder.subscribed
                let unsubscribed = await recorder.unsubscribed
                XCTAssertEqual(subscribed, [SubscriptionGroup.youth.rawValue])
                XCTAssertEqual(unsubscribed, [SubscriptionGroup.youth.rawValue])
            }
        )
    }

    func testGroupSync_rollsBackRemovesOnFailure() async {
        await runRollbackTest(
            toAdd: [],
            toRemove: [SubscriptionGroup.youth.rawValue, SubscriptionGroup.newfamily.rawValue],
            failingOp: .unsubscribe,
            failOn: SubscriptionGroup.newfamily.rawValue,
            expectInverse: { recorder in
                let subscribed = await recorder.subscribed
                let unsubscribed = await recorder.unsubscribed
                XCTAssertTrue(unsubscribed.contains(SubscriptionGroup.youth.rawValue))
                XCTAssertTrue(subscribed.contains(SubscriptionGroup.youth.rawValue),
                              "youth removal must be rolled back via re-subscribe")
            }
        )
    }

    // MARK: - Test helpers

    private actor Recorder {
        var subscribed: [String] = []
        var unsubscribed: [String] = []
        func recordSubscribe(_ g: String) { subscribed.append(g) }
        func recordUnsubscribe(_ g: String) { unsubscribed.append(g) }
    }

    private struct InjectedError: Error {}

    private enum FailingOp { case subscribe, unsubscribe }

    private func runRollbackTest(
        toAdd: [String],
        toRemove: [String],
        failingOp: FailingOp,
        failOn: String,
        expectInverse: (Recorder) async -> Void
    ) async {
        let recorder = Recorder()
        let ops = GroupSyncOps(
            subscribe: { group in
                if failingOp == .subscribe && group == failOn {
                    throw InjectedError()
                }
                await recorder.recordSubscribe(group)
            },
            unsubscribe: { group in
                await recorder.recordUnsubscribe(group)
                if failingOp == .unsubscribe && group == failOn {
                    throw InjectedError()
                }
            }
        )

        do {
            _ = try await GroupSync.apply(toAdd: toAdd, toRemove: toRemove, ops: ops)
            XCTFail("Expected partialFailure")
        } catch GroupStoreError.partialFailure {
            await expectInverse(recorder)
        } catch {
            XCTFail("Wrong error: \(error)")
        }
    }
}

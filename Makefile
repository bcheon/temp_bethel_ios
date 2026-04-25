.PHONY: help install install-deps test test-wp test-ios xcodeproj ci-local clean

ROOT := $(shell pwd)
WP_DIR := wordpress-plugin/bkc-push
IOS_DIR := ios/BKC

help:
	@echo "BKC iOS + WP plugin — local automation"
	@echo ""
	@echo "Targets:"
	@echo "  make install         Install all required tooling (brew + composer + act)"
	@echo "  make test            Run every available test suite (WP + iOS if Mac)"
	@echo "  make test-wp         Run WordPress PHPUnit suite (works anywhere)"
	@echo "  make test-ios        Generate Xcode project + run iOS unit tests (Mac only)"
	@echo "  make xcodeproj       Regenerate ios/BKC/BKC.xcodeproj from project.yml"
	@echo "  make ci-local        Run the GitHub Actions wp-test job locally via act"
	@echo "  make clean           Remove build artifacts and vendor/"

install:
	bash bin/test.sh install

install-deps: install

test:
	bash bin/test.sh test-all

test-wp:
	bash bin/test.sh test-wp

test-ios:
	bash bin/test.sh test-ios

xcodeproj:
	cd $(IOS_DIR) && xcodegen generate

ci-local:
	bash bin/test.sh ci-local

clean:
	rm -rf $(WP_DIR)/vendor
	rm -rf $(IOS_DIR)/build $(IOS_DIR)/DerivedData $(IOS_DIR)/BKC.xcodeproj

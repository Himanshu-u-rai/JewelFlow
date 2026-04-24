<x-app-layout>
    <style>
        .dash-root {
            --dash-ink: #0f172a;
            --dash-action: #14284b;
            --dash-action-strong: #10203d;
            --dash-gold: #f4a300;
            --dash-gold-deep: #d98b00;
            --dash-slate: #475569;
            --dash-muted: #64748b;
            --dash-line: #d7dee8;
            --dash-bg: #f4f6fa;
            --dash-card: #ffffff;
            --dash-accent-soft: #fff7e8;
            --dash-shadow: 0 10px 24px rgba(20, 40, 75, 0.08);
            --dash-shadow-strong: 0 16px 34px rgba(20, 40, 75, 0.12);
            padding-top: 18px;
        }

        .dash-block {
            background: var(--dash-card);
            border: 1px solid var(--dash-line);
            border-radius: 16px;
            box-shadow: var(--dash-shadow);
            contain: layout style;
        }

        @keyframes dashSkeletonWave {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }

        .dash-root.dash-loading .dash-skel {
            position: relative;
            color: transparent !important;
            border-radius: 8px;
            overflow: hidden;
        }

        .dash-root.dash-loading .dash-skel::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: linear-gradient(
                90deg,
                rgba(148, 163, 184, 0.16) 0%,
                rgba(148, 163, 184, 0.3) 48%,
                rgba(148, 163, 184, 0.16) 100%
            );
            background-size: 220% 100%;
            animation: dashSkeletonWave 1.25s linear infinite;
            pointer-events: none;
        }

        .dash-root.dash-loading .dash-kpi-value.dash-skel,
        .dash-root.dash-loading .dash-mini-value.dash-skel {
            min-height: 30px;
        }

        .dash-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .dash-header .page-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: clamp(4px, 0.72vw, 10px);
            row-gap: 6px;
            max-width: min(100%, 62vw);
        }

        .dash-header .page-actions .btn {
            min-height: clamp(34px, 3vw, 40px);
            padding: 0 clamp(9px, 1.2vw, 14px);
            font-size: clamp(10.5px, 0.82vw, 12px);
            border-radius: clamp(8px, 0.75vw, 10px);
            box-shadow: none;
            white-space: nowrap;
        }

        .dash-header .page-actions .btn svg {
            width: clamp(12px, 0.95vw, 14px);
            height: clamp(12px, 0.95vw, 14px);
            margin-right: clamp(3px, 0.4vw, 6px);
            flex-shrink: 0;
        }

        .dash-btn-label-short {
            display: none;
        }

        .dash-subtitle {
            margin-top: 4px;
            font-size: 13px;
            color: var(--dash-muted);
        }

        .dash-header-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        body.dash-command-open {
            overflow: hidden;
        }

        .dash-command-desktop-trigger {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            min-width: 220px;
            width: clamp(220px, 34vw, 420px);
            max-width: 100%;
            padding: 0 14px;
            border: 2px solid transparent;
            border-radius: 8px;
            background: #f3f3f4;
            color: #0d0c22;
            cursor: text;
            transition: 0.3s ease;
        }

        .dash-command-desktop-trigger:hover,
        .dash-command-desktop-trigger:focus {
            border-color: rgba(244, 163, 0, 0.4);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(244, 163, 0, 0.1);
        }

        .dash-command-trigger-copy {
            flex: 1 1 auto;
            min-width: 0;
            text-align: left;
            font-size: 13px;
            font-weight: 400;
            color: #9e9ea7;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-command-trigger-copy-short {
            display: none;
        }

        .dash-command-trigger-kbd {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 22px;
            padding: 0 7px;
            border: 1px solid #e2e8f0;
            border-bottom-width: 2px;
            border-radius: 6px;
            background: #f8fafc;
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            letter-spacing: 0.02em;
        }

        .dash-header .page-actions .dash-command-mobile-trigger {
            display: none;
            align-items: center;
            justify-content: center;
            width: 40px;
            min-width: 40px;
            height: 40px;
            min-height: 40px;
            padding: 0 !important;
            gap: 0 !important;
            border: 2px solid transparent;
            border-radius: 8px;
            background: #f3f3f4 !important;
            color: #9e9ea7 !important;
            cursor: pointer;
            transition: 0.3s ease;
        }

        .dash-command-icon {
            width: 16px;
            height: 16px;
            display: block;
            flex-shrink: 0;
            fill: #9e9ea7;
            color: #9e9ea7;
        }

        .dash-header .page-actions .dash-command-mobile-trigger .dash-command-icon {
            width: 17px;
            height: 17px;
        }

        .dash-command-layer[hidden] {
            display: none !important;
        }

        .dash-command-layer {
            position: fixed;
            inset: 0;
            z-index: 140;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 84px 16px 16px;
        }

        .dash-command-backdrop {
            position: absolute;
            inset: 0;
            border: 0;
            background: rgba(15, 23, 42, 0.42);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            cursor: pointer;
        }

        .dash-command-card {
            position: relative;
            width: min(740px, 100%);
            border: 1px solid var(--dash-line, #d7dee8);
            border-radius: 14px;
            background: var(--dash-card, #ffffff);
            box-shadow: 0 24px 52px rgba(15, 23, 42, 0.24);
            overflow: hidden;
        }

        .dash-command-input-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid #e4ebf4;
        }

        .dash-command-input {
            width: 100%;
            border: 0;
            background: transparent;
            font-size: 15px;
            color: var(--dash-ink);
            outline: none;
        }

        .dash-command-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            padding: 0 8px;
            border: 1px solid var(--dash-line);
            border-radius: 8px;
            background: var(--dash-bg);
            color: var(--dash-slate);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .dash-command-helper {
            padding: 8px 14px;
            font-size: 12px;
            color: var(--dash-muted);
            border-bottom: 1px solid #eaf0f7;
        }

        .dash-command-results {
            max-height: min(58vh, 520px);
            overflow: auto;
            padding: 6px;
            margin: 0;
            list-style: none;
        }

        .dash-command-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 6px;
            padding: 9px 10px;
            border-radius: 10px;
            text-decoration: none;
            border: 1px solid transparent;
        }

        .dash-command-item:hover,
        .dash-command-item.is-active {
            background: var(--dash-bg);
            border-color: var(--dash-line);
        }

        .dash-command-item-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--dash-ink);
            line-height: 1.2;
        }

        .dash-command-item-sub {
            margin-top: 2px;
            font-size: 12px;
            color: var(--dash-muted);
            line-height: 1.25;
        }

        .dash-command-item-type {
            align-self: center;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #0f766e;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 999px;
            padding: 3px 7px;
            white-space: nowrap;
        }

        .dash-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 38px;
            padding: 8px 14px;
            border: 1px solid transparent;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            text-decoration: none;
            transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease;
            box-shadow: var(--dash-shadow);
        }

        .dash-btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.02);
        }

        .dash-btn-primary {
            background: var(--dash-action);
            border-color: var(--dash-action);
            color: #ffffff;
        }

        .dash-btn-accent {
            background: var(--dash-gold);
            border-color: var(--dash-gold);
            color: var(--dash-ink);
        }

        .dash-btn-muted {
            background: #ffffff;
            border-color: var(--dash-line);
            color: var(--dash-action);
        }

        .dash-mobile-fab {
            display: none;
        }

        .dash-mobile-fab-shell {
            position: fixed;
            right: 16px;
            bottom: calc(16px + env(safe-area-inset-bottom, 0px));
            z-index: 70;
        }

        .dash-mobile-fab-nav {
            position: absolute;
            right: 0;
            bottom: calc(100% + 12px);
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .dash-mobile-fab-action {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-width: 172px;
            padding: 11px 14px;
            border-radius: 999px;
            border: 1px solid rgba(20, 40, 75, 0.16);
            background: rgba(255, 255, 255, 0.98);
            color: var(--dash-action);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.01em;
            box-shadow: 0 14px 24px rgba(20, 40, 75, 0.16);
            transform: translateY(18px) scale(0.92);
            opacity: 0;
            transition: transform 180ms ease, opacity 180ms ease, box-shadow 180ms ease;
        }

        .dash-mobile-fab-action:hover {
            box-shadow: 0 18px 28px rgba(20, 40, 75, 0.22);
        }

        .dash-mobile-fab-action svg {
            flex-shrink: 0;
        }

        .dash-mobile-fab-shell.is-open .dash-mobile-fab-nav {
            pointer-events: auto;
        }

        .dash-mobile-fab-shell.is-open .dash-mobile-fab-action {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .dash-mobile-fab-shell.is-open .dash-mobile-fab-action:nth-child(1) {
            transition-delay: 0ms;
        }

        .dash-mobile-fab-shell.is-open .dash-mobile-fab-action:nth-child(2) {
            transition-delay: 36ms;
        }

        .dash-mobile-fab-toggle {
            position: relative;
            width: 56px;
            height: 56px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--dash-action) 0%, var(--dash-action-strong) 100%);
            box-shadow: 0 18px 30px rgba(20, 40, 75, 0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .dash-mobile-fab-toggle::after {
            content: '';
            position: absolute;
            inset: 4px;
            border-radius: inherit;
            border: 1px solid rgba(244, 163, 0, 0.36);
        }

        .dash-mobile-fab-bars {
            position: relative;
            width: 22px;
            height: 18px;
        }

        .dash-mobile-fab-bars span {
            position: absolute;
            left: 0;
            width: 22px;
            height: 2.5px;
            border-radius: 999px;
            background: #ffffff;
            transition: transform 220ms cubic-bezier(0.4, 0, 0.2, 1), opacity 180ms ease, top 220ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dash-mobile-fab-bars span:nth-child(1) {
            top: 1px;
        }

        .dash-mobile-fab-bars span:nth-child(2) {
            top: 8px;
        }

        .dash-mobile-fab-bars span:nth-child(3) {
            top: 15px;
        }

        .dash-mobile-fab-shell.is-open .dash-mobile-fab-bars span:nth-child(1) {
            top: 8px;
            transform: rotate(45deg);
        }

        .dash-mobile-fab-shell.is-open .dash-mobile-fab-bars span:nth-child(2) {
            opacity: 0;
        }

        .dash-mobile-fab-shell.is-open .dash-mobile-fab-bars span:nth-child(3) {
            top: 8px;
            transform: rotate(-45deg);
        }

        .dash-top-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 12px;
        }

        .dash-col-shop {
            grid-column: span 6;
            padding: 14px;
        }

        .dash-col-revenue,
        .dash-col-profit {
            grid-column: span 3;
            padding: 14px;
        }

        .dash-col-quick {
            grid-column: 1 / -1;
        }

        .dash-email-verify {
            background: var(--dash-accent-soft);
            border: 1px solid rgba(244, 163, 0, 0.45);
            padding: 14px 18px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            box-shadow: 0 4px 12px rgba(244, 163, 0, 0.13);
        }

        .dash-email-verify-icon {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .dash-email-verify-copy {
            flex: 1;
            min-width: 0;
        }

        .dash-email-verify-title {
            font-size: 13px;
            font-weight: 700;
            color: #8d5900;
            margin-bottom: 4px;
        }

        .dash-email-verify-subtitle {
            font-size: 12px;
            color: #9f6609;
            margin-bottom: 10px;
        }

        .dash-email-verify-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .dash-email-input {
            flex: 1;
            min-width: 180px;
            max-width: 260px;
            padding: 7px 10px;
            border: 1px solid var(--dash-gold-deep);
            border-radius: 12px;
            font-size: 13px;
            background: #fffcf3;
            outline: none;
        }

        .dash-email-btn {
            padding: 7px 16px;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .dash-email-btn-send {
            background: var(--dash-gold);
            color: var(--dash-ink);
            white-space: nowrap;
        }

        .dash-email-btn-later,
        .dash-email-btn-resend {
            padding: 7px 10px;
            background: transparent;
            border: 1px solid var(--dash-gold-deep);
            color: #9f6609;
        }

        .dash-email-step-error {
            font-size: 12px;
            color: #b91c1c;
            margin-top: 5px;
            display: none;
        }

        .dash-email-step2 {
            display: none;
        }

        .dash-email-step2-note {
            font-size: 12px;
            color: #855607;
            margin-bottom: 8px;
        }

        .dash-email-otp-input {
            width: 130px;
            padding: 7px 10px;
            border: 1px solid var(--dash-gold-deep);
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.1em;
            background: #fffcf3;
            outline: none;
            text-align: center;
        }

        .dash-email-btn-verify {
            background: var(--dash-action);
            color: #fff;
        }

        .dash-email-countdown {
            font-size: 11px;
            color: #9f6609;
        }

        .dash-email-success {
            display: none;
            font-size: 13px;
            font-weight: 600;
            color: #15803d;
        }

        @media (min-width: 1281px) {
            .os-windows .dash-col-shop {
                grid-column: span 3;
            }
        }

        .dash-profile-top {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .dash-shop-card {
            display: grid;
            grid-template-rows: auto 1fr auto;
            gap: 10px;
            background: #ffffff;
        }

        .dash-shop-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }

        .dash-shop-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }

        .dash-shop-meta-label {
            margin: 0;
            font-size: 10px;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--dash-muted);
            font-weight: 700;
        }

        .dash-shop-info {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .dash-shop-info .dash-meta {
            margin: 0;
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-shop-info .dash-meta strong {
            color: var(--dash-action);
            font-weight: 700;
            margin-right: 6px;
        }

        .dash-logo-box {
            width: 54px;
            height: 54px;
            border: 1px solid var(--dash-line);
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 15px;
            font-weight: 700;
            color: #8b97a9;
        }

        .dash-logo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .dash-shop-name {
            margin: 0;
            font-size: 22px;
            line-height: 1.05;
            color: var(--dash-ink);
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .dash-meta {
            margin-top: 4px;
            font-size: 12px;
            color: var(--dash-muted);
        }

        .dash-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border: 1px solid rgba(244, 163, 0, 0.38);
            background: rgba(244, 163, 0, 0.14);
            color: #986000;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .dash-shop-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .dash-kpi-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 9px;
            color: var(--jf-text-subtle, #98a2b3);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 600;
            line-height: 1.2;
        }

        .dash-kpi-label svg {
            width: 12px;
            height: 12px;
            opacity: 0.8;
            flex-shrink: 0;
        }

        .dash-kpi-value {
            margin-top: 8px;
            font-size: 30px;
            line-height: 1;
            font-weight: 800;
            color: var(--dash-ink);
            letter-spacing: -0.02em;
        }

        .dash-kpi-sub {
            margin-top: 7px;
            font-size: 12px;
            color: var(--dash-muted);
        }

        .dash-top-kpi {
            display: grid;
            grid-template-rows: auto 1fr auto;
            gap: 8px;
        }

        .dash-top-kpi-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .dash-top-kpi-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .dash-top-kpi-icon svg {
            width: 20px;
            height: 20px;
            stroke-width: 2.2;
        }

        .dash-top-kpi-meta {
            margin: 0;
            font-size: 10px;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            white-space: nowrap;
            text-align: right;
        }

        .dash-top-kpi-value {
            margin-top: 0;
        }

        .dash-top-kpi-foot {
            margin-top: auto;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }

        .dash-top-kpi-title {
            margin: 0;
            font-size: 11px;
            line-height: 1.2;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .dash-top-kpi-note {
            margin: 0;
            font-size: 10px;
            line-height: 1.2;
            color: var(--dash-muted);
            white-space: nowrap;
        }

        .dash-top-kpi-revenue {
            background: #ffffff;
        }

        .dash-top-kpi-revenue .dash-top-kpi-icon {
            border-color: rgba(217, 139, 0, 0.24);
            background: rgba(244, 163, 0, 0.16);
            color: #986000;
        }

        .dash-top-kpi-revenue .dash-top-kpi-meta,
        .dash-top-kpi-revenue .dash-top-kpi-title {
            color: #986000;
        }

        .dash-top-kpi-revenue .dash-top-kpi-value {
            color: #8f5c00;
        }

        .dash-top-kpi-profit {
            background: #ffffff;
        }

        .dash-top-kpi-profit .dash-top-kpi-icon {
            border-color: rgba(20, 40, 75, 0.2);
            background: rgba(20, 40, 75, 0.1);
            color: var(--dash-action);
        }

        .dash-top-kpi-profit .dash-top-kpi-meta,
        .dash-top-kpi-profit .dash-top-kpi-title {
            color: var(--dash-action);
        }

        .dash-top-kpi-profit.is-loss .dash-top-kpi-icon {
            border-color: rgba(180, 35, 24, 0.22);
            background: rgba(180, 35, 24, 0.08);
            color: #b42318;
        }

        .dash-top-kpi-profit.is-loss .dash-top-kpi-meta,
        .dash-top-kpi-profit.is-loss .dash-top-kpi-title {
            color: #b42318;
        }

        .dash-warnings {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid rgba(244, 163, 0, 0.45);
            background: #fffbf2;
            box-shadow: var(--dash-shadow);
        }

        .dash-warning-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border: 1px solid rgba(150, 90, 0, 0.24);
            background: rgba(255, 255, 255, 0.55);
            font-size: 12px;
            color: #8a5300;
            font-weight: 600;
        }

        .dash-mini-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
        }

        .dash-mini {
            padding: 12px;
            border-radius: var(--jf-radius);
            box-shadow: var(--jf-shadow-sm);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: grid;
            grid-template-rows: auto 1fr auto;
            align-content: start;
            aspect-ratio: 16 / 9;
            min-height: 108px;
        }

        .dash-mini-dark {
            background: #1a2029;
            border-color: #323d4c;
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.24);
        }

        .dash-mini-dark .dash-kpi-label,
        .dash-mini-dark .dash-mini-value,
        .dash-mini-dark .dash-kpi-sub,
        .dash-mini-dark .dash-kpi-sub:link,
        .dash-mini-dark .dash-kpi-sub:visited {
            color: #ffffff;
        }

        .dash-mini-dark .dash-kpi-sub {
            opacity: 0.9;
        }

        .dash-mini-kpi {
            gap: 7px;
        }

        .dash-mini-kpi-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .dash-mini-kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.34);
            background: rgba(255, 255, 255, 0.14);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .dash-mini-kpi-icon svg {
            width: 22px;
            height: 22px;
            color: #ffffff;
            opacity: 1;
        }

        .dash-mini-kpi-meta {
            margin: 0;
            font-size: 10px;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.76);
            white-space: nowrap;
            text-align: right;
        }

        .dash-mini-kpi .dash-mini-value {
            font-size: clamp(28px, 2vw, 34px);
            line-height: 1.02;
        }

        .dash-mini-kpi-foot {
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }

        .dash-mini-kpi-title {
            margin: 0;
            font-size: 11px;
            line-height: 1.2;
            color: #ffffff;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .dash-mini-kpi-link {
            font-size: 11px;
            line-height: 1.2;
            color: rgba(255, 255, 255, 0.88);
            text-decoration: none;
            font-weight: 700;
            white-space: nowrap;
        }

        .dash-mini-kpi-link:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        @supports (grid-template-rows: subgrid) {
            .dash-mini-grid {
                grid-template-rows: auto auto auto;
            }

            .dash-mini {
                grid-template-rows: subgrid;
                grid-row: span 3;
            }
        }

        .dash-mini-value {
            font-size: 22px;
            line-height: 1.05;
            font-weight: 800;
            color: var(--jf-ink, #101828);
            letter-spacing: -0.01em;
            margin: 0;
            display: block;
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .dash-mini .dash-kpi-label {
                font-size: 10px;
                gap: 5px;
            }

            .dash-mini .dash-kpi-label svg {
                width: 12px;
                height: 12px;
            }

            .dash-mini .dash-mini-value {
                font-size: 24px;
            }
        }

        @media (min-width: 1025px) {
            .dash-mini .dash-kpi-label {
                font-size: clamp(11px, 0.75vw, 12px);
                gap: 6px;
            }

            .dash-mini .dash-kpi-label svg {
                width: clamp(13px, 0.9vw, 15px);
                height: clamp(13px, 0.9vw, 15px);
            }

            .dash-mini .dash-mini-value {
                font-size: clamp(26px, 2vw, 32px);
            }
        }

        .dash-reorder-widget {
            padding: 12px 14px;
            border-color: rgba(217, 119, 6, 0.38);
            background: #ffffff;
        }

        .dash-reorder-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .dash-reorder-title {
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 16px;
            color: #9a6200;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .dash-reorder-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(154, 98, 0, 0.28);
            background: rgba(255, 255, 255, 0.66);
            color: #8b5200;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }

        .dash-reorder-link:hover {
            background: #fff;
        }

        .dash-reorder-list {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .dash-reorder-item {
            min-width: 0;
            border-radius: 12px;
            border: 1px solid rgba(154, 98, 0, 0.16);
            background: rgba(255, 255, 255, 0.82);
            padding: 8px 10px;
        }

        .dash-reorder-item-head {
            font-size: 12px;
            color: #7a4d02;
            font-weight: 700;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-reorder-item-meta {
            margin-top: 4px;
            font-size: 11px;
            color: #8f5300;
            font-weight: 600;
        }

        .dash-reorder-item-meta strong {
            color: #b42318;
            font-weight: 800;
        }

        .dash-reorder-more {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            border: 1px dashed rgba(154, 98, 0, 0.28);
            background: rgba(255, 255, 255, 0.55);
            font-size: 12px;
            color: #8f5300;
            font-weight: 700;
            min-height: 58px;
            padding: 8px;
        }

        .dash-main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr);
            gap: 12px;
        }

        .dash-panel-pad {
            padding: 14px;
        }

        .dash-insight-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
            gap: 12px;
            align-items: stretch;
        }

        .dash-chart-panel,
        .dash-monthly-panel {
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .dash-chart-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-end;
            margin-bottom: 12px;
        }

        .dash-chart-title {
            margin: 0;
            font-size: 18px;
            color: #0f172a;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .dash-chart-sub {
            margin-top: 4px;
            font-size: 11.5px;
            color: #64748b;
            font-weight: 500;
        }

        .dash-chart-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px;
            border-radius: 12px;
            border: 1px solid #d5dde8;
            background: #f2f5fa;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
            flex-wrap: nowrap;
        }

        .dash-toggle-btn {
            min-height: 32px;
            padding: 6px 12px;
            border: 0;
            border-radius: 9px;
            background: transparent;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            white-space: nowrap;
        }

        .dash-toggle-btn.is-active {
            background: #111827;
            color: #ffffff;
            box-shadow: 0 8px 14px rgba(15, 23, 42, 0.24);
        }

        .dash-chip-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            gap: 8px;
            margin-bottom: 9px;
        }

        .dash-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            font-size: 11px;
            color: #475569;
            font-weight: 700;
            border-radius: 999px;
        }

        .dash-chart-shell {
            border: 1px solid #d9e1ec;
            background: #f8fafc;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.95);
            padding: 12px 12px 10px;
            border-radius: 14px;
            overflow: hidden;
        }

        .dash-chart-grid {
            display: grid;
            grid-template-columns: 40px 1fr;
            gap: 8px;
            min-height: 246px;
        }

        .dash-axis {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: flex-end;
            padding-right: 4px;
            font-size: 10px;
            color: #64748b;
            font-weight: 700;
        }

        .dash-bars {
            position: relative;
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 12px;
            align-items: end;
            min-height: 220px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background-image: repeating-linear-gradient(
                to top,
                rgba(148, 163, 184, 0.18),
                rgba(148, 163, 184, 0.18) 1px,
                transparent 1px,
                transparent 25%
            );
            background-size: 100% 100%;
            background-color: #fcfdff;
            padding: 10px 10px 6px;
        }

        .dash-bars-finance {
            gap: 10px;
        }

        .dash-col {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            min-width: 0;
            height: 100%;
            transition: transform 140ms ease;
        }

        .dash-col:hover {
            transform: translateY(-2px);
        }

        .dash-count {
            min-height: 16px;
            font-size: 11px;
            line-height: 1;
            color: #1f2937;
            font-weight: 800;
        }

        .dash-rail {
            width: 100%;
            height: 156px;
            display: flex;
            align-items: flex-end;
        }

        .dash-bar {
            width: 72%;
            margin: 0 auto;
            min-height: 4px;
            border: 0;
            border-radius: 11px 11px 6px 6px;
            background: #b8c4d3;
            box-shadow: 0 8px 14px rgba(15, 23, 42, 0.12);
            transition: filter 140ms ease, box-shadow 140ms ease;
        }

        .dash-bar.is-today {
            background: #111827;
            box-shadow: 0 12px 20px rgba(15, 23, 42, 0.28);
        }

        .dash-legend {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .dash-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            color: #64748b;
            font-weight: 700;
        }

        .dash-legend-dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            flex-shrink: 0;
        }

        .dash-legend-dot.neutral {
            background: #b8c4d3;
        }

        .dash-legend-dot.today {
            background: #111827;
        }

        .dash-legend-dot.revenue {
            background: #1f2937;
        }

        .dash-legend-dot.profit {
            background: #64748b;
        }

        .dash-legend-dot.loss {
            background: #ef4444;
        }

        .dash-finance-values {
            min-height: 22px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            font-size: 10px;
            line-height: 1;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .dash-finance-values .rev {
            color: #0f172a;
        }

        .dash-finance-values .pro {
            color: #475569;
        }

        .dash-finance-values .pro.is-loss {
            color: #b91c1c;
        }

        .dash-rail-finance {
            width: 100%;
            height: 156px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 5px;
        }

        .dash-finance-bar {
            width: calc(50% - 2.5px);
            min-height: 4px;
            border-radius: 9px 9px 5px 5px;
            border: 0;
        }

        .dash-finance-bar.revenue {
            background: #1f2937;
            box-shadow: 0 8px 14px rgba(15, 23, 42, 0.16);
        }

        .dash-finance-bar.profit {
            background: #64748b;
            box-shadow: 0 8px 14px rgba(15, 23, 42, 0.13);
        }

        .dash-finance-bar.profit.is-loss {
            background: #ef4444;
            box-shadow: 0 8px 14px rgba(185, 28, 28, 0.18);
        }

        .dash-day {
            font-size: 11px;
            color: #475569;
            font-weight: 700;
        }

        .dash-note {
            margin-top: 8px;
            font-size: 10px;
            color: #64748b;
            font-weight: 600;
        }

        .dash-monthly-shell {
            display: flex;
            flex-direction: column;
            gap: 8px;
            height: 100%;
            padding: 0;
            border: 0;
            background: transparent;
            box-shadow: none;
        }

        .dash-monthly-header {
            margin-bottom: 8px;
        }

        .dash-monthly-title {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }

        .dash-monthly-sub {
            margin-top: 3px;
            font-size: 11px;
            color: #667085;
        }

        .dash-monthly-bars {
            --monthly-count: 30;
            position: relative;
            display: grid;
            grid-template-columns: repeat(var(--monthly-count), minmax(0, 1fr));
            align-items: end;
            gap: 4px;
            height: 190px;
            padding: 10px 8px 6px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background-image: repeating-linear-gradient(
                to top,
                rgba(148, 163, 184, 0.16),
                rgba(148, 163, 184, 0.16) 1px,
                transparent 1px,
                transparent 25%
            );
            background-size: 100% 100%;
            background-color: #fcfdff;
        }

        .dash-monthly-col {
            height: 100%;
            display: flex;
            align-items: flex-end;
        }

        .dash-monthly-bar-wrap {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: flex-end;
        }

        .dash-monthly-bar {
            width: 72%;
            margin: 0 auto;
            min-height: 6px;
            border-radius: 8px 8px 4px 4px;
            background: #b8c4d3;
            box-shadow: 0 6px 10px rgba(15, 23, 42, 0.12);
            transition: filter 140ms ease, box-shadow 140ms ease;
        }

        .dash-monthly-col:hover .dash-monthly-bar {
            filter: brightness(0.96);
        }

        .dash-monthly-bar.is-peak {
            background: #111827;
            box-shadow: 0 10px 16px rgba(15, 23, 42, 0.24);
        }

        .dash-monthly-axis {
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #64748b;
            font-weight: 700;
        }

        .dash-monthly-peaks {
            margin-top: 6px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
        }

        .dash-monthly-peak {
            min-width: 0;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            padding: 8px 10px;
        }

        .dash-monthly-peak-label {
            font-size: 10px;
            color: #64748b;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-monthly-peak-value {
            margin-top: 2px;
            font-size: 12px;
            color: #0f172a;
            font-weight: 800;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-monthly-peak-meta {
            margin-top: 1px;
            font-size: 10px;
            color: #64748b;
            font-weight: 600;
        }

        .dash-quick-strip {
            padding: 8px 12px;
        }

        .dash-quick-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .dash-quick-title {
            margin: 0;
            font-size: 16px;
            color: #111827;
            font-weight: 800;
        }

        .dash-quick-sub {
            font-size: 11px;
            color: #667085;
            font-weight: 600;
        }

        .dash-quick-list {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 9px;
        }

        .dash-quick-item {
            --quick-accent: #ffffff;
            --quick-border: #244270;
            --quick-hover-border: #2d4e81;
            --quick-ink: #ffffff;
            --quick-sub: #f1f5f9;
            --quick-icon-bg: #26477a;
            --quick-icon-border: #456392;
            --quick-icon-ink: var(--quick-accent);
            --quick-bg: #14284b;
            --quick-hover-bg: #1b3460;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--quick-border);
            background: var(--quick-bg);
            text-decoration: none;
            color: var(--quick-ink);
            box-shadow: 0 8px 16px rgba(15, 23, 42, 0.24);
            transition: transform 120ms ease, box-shadow 120ms ease, background 120ms ease, border-color 120ms ease;
            min-height: 54px;
            border-radius: 12px;
        }

        .dash-quick-item:active {
            transform: translateY(1px);
        }

        .dash-quick-icon {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--quick-icon-border);
            background: var(--quick-icon-bg);
            color: var(--quick-icon-ink);
            flex-shrink: 0;
            border-radius: 9px;
        }

        .dash-quick-icon svg {
            width: 17px;
            height: 17px;
            stroke-width: 2.2;
        }

        .dash-quick-copy {
            min-width: 0;
        }

        .dash-quick-item strong {
            display: block;
            font-size: 13px;
            color: var(--quick-ink);
            font-weight: 700;
            transition: color 120ms ease;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-quick-item span small {
            display: block;
            margin-top: 2px;
            font-size: 11px;
            color: var(--quick-sub);
            font-weight: 500;
            transition: color 120ms ease, opacity 120ms ease;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-quick-sale {
            --quick-accent: #ffffff;
            --quick-icon-bg: #26477a;
            --quick-icon-border: #456392;
            --quick-hover-border: #2d4e81;
        }

        .dash-quick-customer {
            --quick-accent: #ffffff;
            --quick-icon-bg: #26477a;
            --quick-icon-border: #456392;
            --quick-hover-border: #2d4e81;
        }

        .dash-quick-itemadd {
            --quick-accent: #ffffff;
            --quick-icon-bg: #26477a;
            --quick-icon-border: #456392;
            --quick-hover-border: #2d4e81;
        }

        .dash-quick-closing {
            --quick-accent: #ffffff;
            --quick-icon-bg: #26477a;
            --quick-icon-border: #456392;
            --quick-hover-border: #2d4e81;
        }

        @media (hover: hover) and (pointer: fine) {
            .dash-block.dash-mini:hover {
                transform: translateY(-2px);
                box-shadow: var(--jf-shadow-md);
            }

            .dash-quick-item:hover {
                transform: translateY(-1px);
                background: var(--quick-hover-bg);
                border-color: var(--quick-hover-border);
                box-shadow: 0 12px 20px rgba(15, 23, 42, 0.3);
            }
        }

        .dash-list-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .dash-table-card {
            border-radius: 24px !important;
            border: 1px solid #d7e1ef !important;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
            overflow: hidden !important;
            clip-path: inset(0 round 24px);
        }

        .dash-table-card .dash-list-head {
            border-top-left-radius: 24px;
            border-top-right-radius: 24px;
            background: #f5f8fe;
        }

        @media (max-width: 1600px) {
            .dash-list-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .dash-list-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid #e5e7eb;
            background: #fbfcfe;
        }

        .dash-list-title {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .dash-list-title-icon {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            border: 1px solid #111827;
            background: #111827;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .dash-list-title-icon svg {
            width: 15px;
            height: 15px;
            stroke-width: 2.1;
        }

        .dash-list-head h2 {
            margin: 0;
            font-size: 16px;
            color: #111827;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .dash-list-link {
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #dbe3ee;
            border-radius: 8px;
            padding: 5px 12px;
            transition: background 120ms, box-shadow 120ms;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
            display: inline-block;
        }
        .dash-list-link:hover {
            background: #111827;
            color: #fff;
            border-color: #111827;
        }

        .dash-table-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 10px;
            align-items: center;
            padding: 8px 14px;
            border-bottom: 1px solid #e8edf4;
            background: #f8fafc;
            font-size: 10px;
            color: #64748b;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .dash-table-head span:nth-child(2),
        .dash-table-head span:nth-child(3) {
            text-align: right;
        }

        .dash-table-wrap {
            margin: 10px 12px 12px;
            border: 1px solid #d8e2ef;
            border-radius: 16px;
            overflow: hidden;
            background: #ffffff;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.95);
        }

        .dash-table-body {
            background: #ffffff;
        }

        .dash-table-body .dash-row:first-child {
            border-top: 0;
        }

        .dash-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-top: 1px solid #eef2f7;
            text-decoration: none;
            color: inherit;
            background: #ffffff;
        }

        .dash-row:hover {
            background: #f8fafd;
        }

        .dash-row-main {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dash-row-icon {
            width: 34px;
            height: 34px;
            border-radius: 11px;
            border: 1px solid #d7e1ef;
            background: #f8fafc;
            color: #334155;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            flex-shrink: 0;
        }

        .dash-row-icon svg {
            width: 16px;
            height: 16px;
            stroke-width: 2.15;
        }

        .dash-row-icon.invoice {
            background: #111827;
            border-color: #111827;
            color: #ffffff;
        }

        .dash-row-icon.repair {
            background: #f1f5f9;
            border-color: #d4deed;
            color: #0f172a;
        }

        .dash-row-icon.customer {
            background: #e2e8f0;
            border-color: #c8d4e5;
            color: #0f172a;
        }

        .dash-row-copy {
            min-width: 0;
        }

        .dash-row-title {
            font-size: 13px;
            line-height: 1.2;
            color: #0f172a;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-row-meta {
            margin-top: 2px;
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-row-mid {
            font-size: 12px;
            color: #334155;
            font-weight: 700;
            white-space: nowrap;
            text-align: right;
            padding-left: 8px;
        }

        .dash-row-end {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            white-space: nowrap;
            padding-left: 8px;
        }

        .dash-row-arrow {
            width: 14px;
            height: 14px;
            color: #94a3b8;
            flex-shrink: 0;
        }

        .dash-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 22px;
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 10px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .dash-tag.invoice {
            background: #f8fafc;
            border-color: #dbe3ee;
            color: #334155;
        }

        .dash-tag.pending {
            background: #f8fafc;
            border-color: #d5dee9;
            color: #475569;
        }

        .dash-tag.progress {
            background: #eef6ff;
            border-color: #c9ddff;
            color: #1d4ed8;
        }

        .dash-tag.ready {
            background: #fff8e6;
            border-color: #f8d891;
            color: #9a6700;
        }

        .dash-tag.delivered {
            background: #ecfdf3;
            border-color: #b7ebcd;
            color: #166534;
        }

        .dash-tag.default {
            background: #f8fafc;
            border-color: #dbe3ee;
            color: #334155;
        }

        .dash-top-rank {
            position: absolute;
            right: -6px;
            bottom: -6px;
            min-width: 16px;
            height: 16px;
            border-radius: 999px;
            background: #111827;
            color: #ffffff;
            border: 1px solid #ffffff;
            font-size: 9px;
            font-weight: 800;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .dash-amount {
            font-size: 14px;
            color: #0f5132;
            font-weight: 800;
            letter-spacing: -0.01em;
            white-space: nowrap;
        }

        .dash-empty {
            padding: 24px 14px;
            text-align: center;
            font-size: 13px;
            color: #667085;
            border-top: 1px solid #eef2f7;
        }

        /* Theme normalization pass: map major surfaces to dashboard palette tokens */
        .dash-root {
            background: var(--dash-bg);
        }

        .dash-root .dash-block {
            border-color: #cfd9e8;
            box-shadow: 0 10px 24px rgba(20, 40, 75, 0.08);
        }

        .dash-root .dash-mini-dark {
            background: linear-gradient(180deg, #1a335d 0%, #14284b 100%);
            border-color: #2e4f81;
            box-shadow: 0 12px 22px rgba(20, 40, 75, 0.28);
        }

        .dash-root .dash-mini-kpi-icon {
            border-color: rgba(244, 163, 0, 0.55);
            background: rgba(244, 163, 0, 0.14);
            color: #f4c354;
        }

        .dash-root .dash-mini-kpi-meta {
            color: rgba(229, 236, 247, 0.86);
        }

        .dash-root .dash-table-card {
            border-color: #cfd9e8 !important;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%) !important;
            box-shadow: 0 12px 24px rgba(20, 40, 75, 0.1);
        }

        .dash-root .dash-table-card .dash-list-head,
        .dash-root .dash-list-head {
            background: #f3f7fe;
            border-bottom-color: #dde6f2;
        }

        .dash-root .dash-table-head {
            background: #f4f8ff;
            border-bottom-color: #e0e8f3;
            color: #5a6f8d;
        }

        .dash-root .dash-row {
            border-top-color: #e9eff7;
        }

        .dash-root .dash-row:hover {
            background: #f5f9ff;
        }

        .dash-root .dash-list-title-icon,
        .dash-root .dash-row-icon.invoice,
        .dash-root .dash-top-rank {
            background: var(--dash-action);
            border-color: var(--dash-action);
        }

        .dash-root .dash-list-link {
            color: var(--dash-action);
            border-color: #cfd9e8;
            background: #ffffff;
        }

        .dash-root .dash-list-link:hover {
            background: var(--dash-action);
            border-color: var(--dash-action);
            color: #ffffff;
        }

        .dash-root .dash-chart-shell,
        .dash-root .dash-bars,
        .dash-root .dash-monthly-bars {
            border-color: #dbe5f1;
            background-color: #fbfdff;
        }

        .dash-root .dash-bar,
        .dash-root .dash-monthly-bar {
            background: #8ea5c4;
        }

        .dash-root .dash-bar.is-today,
        .dash-root .dash-monthly-bar.is-peak,
        .dash-root .dash-legend-dot.today,
        .dash-root .dash-legend-dot.revenue,
        .dash-root .dash-finance-bar.revenue {
            background: var(--dash-action);
        }

        .dash-root .dash-legend-dot.neutral {
            background: #8ea5c4;
        }

        .dash-root .dash-legend-dot.profit,
        .dash-root .dash-finance-bar.profit {
            background: #5f789a;
        }

        .dash-root .dash-toggle-btn.is-active {
            background: var(--dash-action);
            box-shadow: 0 8px 14px rgba(20, 40, 75, 0.24);
        }

        .dash-root .dash-chart-title,
        .dash-root .dash-monthly-title {
            color: var(--dash-ink);
        }

        .dash-root .dash-chart-sub,
        .dash-root .dash-monthly-sub,
        .dash-root .dash-axis,
        .dash-root .dash-day,
        .dash-root .dash-note {
            color: var(--dash-muted);
        }

        .dash-root .dash-top-kpi-profit .dash-top-kpi-meta,
        .dash-root .dash-top-kpi-profit .dash-top-kpi-title,
        .dash-root .dash-top-kpi-profit .dash-top-kpi-value {
            color: var(--dash-action);
        }

        .dash-root .dash-top-kpi-profit.is-loss .dash-top-kpi-meta,
        .dash-root .dash-top-kpi-profit.is-loss .dash-top-kpi-title,
        .dash-root .dash-top-kpi-profit.is-loss .dash-top-kpi-value {
            color: #b42318;
        }

        .dash-root .dash-top-kpi-revenue .dash-top-kpi-meta,
        .dash-root .dash-top-kpi-revenue .dash-top-kpi-title,
        .dash-root .dash-top-kpi-revenue .dash-top-kpi-value {
            color: #8f5c00;
        }

        @media (max-width: 1280px) {
            .dash-root {
                padding-top: 14px;
            }

            .dash-col-shop,
            .dash-col-revenue,
            .dash-col-profit {
                grid-column: span 12;
            }

            .dash-insight-grid {
                grid-template-columns: 1fr;
            }

            .dash-reorder-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dash-quick-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dash-main-grid {
                grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
            }

            .dash-list-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .dash-main-grid,
            .dash-list-grid {
                grid-template-columns: 1fr;
            }

            .dash-mini-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dash-command-desktop-trigger {
                width: clamp(190px, 32vw, 300px);
                min-width: 190px;
            }

            .dash-command-trigger-kbd {
                display: none;
            }

            .dash-command-trigger-copy-full {
                display: none;
            }

            .dash-command-trigger-copy-short {
                display: inline;
            }

            .dash-header .page-actions {
                max-width: min(100%, 66vw);
            }

            .dash-header .page-actions .dash-btn-label-full {
                display: none;
            }

            .dash-header .page-actions .dash-btn-label-short {
                display: inline;
            }
        }

        @media (max-width: 860px) {
            .dash-command-desktop-trigger {
                width: 40px;
                min-width: 40px;
                padding: 0 !important;
                gap: 0 !important;
                justify-content: center;
                border-radius: 10px;
            }

            .dash-command-trigger-copy-short {
                display: none;
            }

            .dash-header .page-actions {
                max-width: min(100%, 72vw);
            }

            .dash-header .page-actions .btn {
                min-height: 34px;
                padding-inline: 9px;
                font-size: 10.5px;
            }

            .dash-header .page-actions .btn svg {
                margin-right: 3px;
            }
        }

        @media (max-width: 680px) {
            .dash-header {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                align-items: start;
                gap: 10px;
            }

            .dash-header > .min-w-0 {
                text-align: center;
            }

            .dash-header .page-title {
                font-size: 21px;
                line-height: 1.05;
                letter-spacing: -0.03em;
            }

            .dash-header .page-subtitle {
                margin-top: 3px;
                font-size: 12px;
            }

            .dash-header .page-actions {
                display: flex;
                width: auto;
                flex-shrink: 0;
                justify-content: flex-end;
                align-items: center;
                gap: 6px;
            }

            .dash-header .page-actions .btn {
                width: auto;
                min-height: 40px;
                padding: 9px 16px;
                justify-content: center;
                font-size: 11.5px;
                box-shadow: none;
                border-radius: 10px;
            }

            .dash-mobile-float-target {
                display: none !important;
            }

            .dash-command-desktop-trigger {
                display: none !important;
            }

            .dash-header .page-actions .dash-command-mobile-trigger {
                display: inline-flex;
            }

            .dash-command-layer {
                align-items: flex-start;
                justify-content: center;
                padding: 70px 10px 12px;
            }

            .dash-command-card {
                width: min(460px, 100%);
                border-radius: 12px;
            }

            .dash-command-input-row {
                padding: 10px 11px;
            }

            .dash-command-input {
                font-size: 14px;
            }

            .dash-command-results {
                max-height: 62vh;
            }

            .dash-mobile-fab {
                display: block;
            }

            .dash-top-grid {
                gap: 10px;
            }

            .dash-col-shop {
                padding: 12px;
                order: 1;
            }

            .dash-col-quick {
                order: 2;
            }

            .dash-col-revenue,
            .dash-col-profit {
                grid-column: span 6;
                padding: 12px;
                order: 3;
            }

            .dash-col-revenue .dash-kpi-value,
            .dash-col-profit .dash-kpi-value {
                margin-top: 6px;
                font-size: 22px;
            }

            .dash-top-kpi {
                gap: 7px;
            }

            .dash-top-kpi-icon {
                width: 34px;
                height: 34px;
                border-radius: 10px;
            }

            .dash-top-kpi-icon svg {
                width: 17px;
                height: 17px;
            }

            .dash-top-kpi-meta {
                font-size: 9px;
            }

            .dash-top-kpi-title {
                font-size: 10px;
            }

            .dash-top-kpi-note {
                font-size: 9px;
            }

            .dash-profile-top {
                align-items: flex-start;
                gap: 10px;
            }

            .dash-shop-card {
                gap: 8px;
            }

            .dash-shop-card-head {
                gap: 8px;
            }

            .dash-shop-brand {
                gap: 8px;
            }

            .dash-shop-meta-label {
                font-size: 9px;
            }

            .dash-logo-box {
                width: 46px;
                height: 46px;
                font-size: 13px;
            }

            .dash-shop-name {
                font-size: 18px;
                line-height: 1.08;
            }

            .dash-meta {
                font-size: 11px;
                line-height: 1.35;
            }

            .dash-shop-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .dash-shop-actions .dash-btn {
                width: 100%;
                min-height: 36px;
                padding: 8px 10px;
                font-size: 12px;
                box-shadow: none;
            }

            .dash-warnings {
                gap: 8px;
                padding: 10px;
            }

            .dash-warning-chip {
                font-size: 11px;
                padding: 5px 8px;
            }

            .dash-mini-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .dash-mini {
                padding: 10px 11px;
                aspect-ratio: auto;
                min-height: 92px;
            }

            .dash-mini:last-child:nth-child(odd) {
                grid-column: 1 / -1;
            }

            .dash-mini-value {
                margin-top: 4px;
                font-size: 22px;
            }

            .dash-kpi-label {
                font-size: 9px;
                gap: 5px;
            }

            .dash-kpi-label svg {
                width: 11px;
                height: 11px;
            }

            .dash-mini-kpi {
                gap: 6px;
            }

            .dash-mini-kpi-icon {
                width: 36px;
                height: 36px;
                border-radius: 10px;
            }

            .dash-mini-kpi-icon svg {
                width: 18px;
                height: 18px;
            }

            .dash-mini-kpi-meta {
                font-size: 9px;
            }

            .dash-mini-kpi .dash-mini-value {
                font-size: 24px;
            }

            .dash-mini-kpi-title {
                font-size: 10px;
            }

            .dash-mini-kpi-link {
                font-size: 10px;
            }

            .dash-kpi-sub {
                margin-top: 4px;
                font-size: 11px;
            }

            .dash-main-grid {
                gap: 10px;
            }

            .dash-panel-pad {
                padding: 12px;
            }

            .dash-chart-head {
                flex-direction: column;
                gap: 8px;
                margin-bottom: 8px;
            }

            .dash-chart-title {
                font-size: 17px;
            }

            .dash-chart-sub {
                font-size: 11px;
            }

            .dash-chart-toggle {
                width: 100%;
                justify-content: space-between;
            }

            .dash-toggle-btn {
                flex: 1;
                min-width: 0;
                font-size: 10px;
                padding: 6px 8px;
            }

            .dash-chart-grid {
                grid-template-columns: 30px 1fr;
                min-height: 200px;
            }

            .dash-chip-row {
                justify-content: flex-start;
                gap: 6px;
            }

            .dash-chip {
                font-size: 10px;
                padding: 4px 7px;
            }

            .dash-chart-shell {
                padding: 10px 8px 8px;
            }

            .dash-axis {
                font-size: 10px;
            }

            .dash-bars {
                gap: 6px;
                min-height: 180px;
                padding: 8px 6px 4px;
            }

            .dash-bars-finance {
                gap: 5px;
            }

            .dash-rail {
                height: 124px;
            }

            .dash-rail-finance {
                height: 124px;
                gap: 3px;
            }

            .dash-finance-values {
                font-size: 9px;
            }

            .dash-count,
            .dash-day,
            .dash-note {
                font-size: 10px;
            }

            .dash-legend {
                gap: 8px;
            }

            .dash-legend-item {
                font-size: 9px;
            }

            .dash-monthly-title {
                font-size: 15px;
            }

            .dash-monthly-sub {
                font-size: 10px;
            }

            .dash-monthly-bars {
                height: 170px;
                gap: 3px;
                padding: 8px 6px 5px;
            }

            .dash-monthly-bar {
                width: 82%;
                border-radius: 6px 6px 3px 3px;
            }

            .dash-monthly-peaks {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dash-reorder-widget {
                padding: 10px 12px;
            }

            .dash-reorder-head {
                margin-bottom: 8px;
            }

            .dash-reorder-title {
                font-size: 14px;
            }

            .dash-reorder-link {
                padding: 5px 8px;
                font-size: 10px;
            }

            .dash-reorder-list {
                grid-template-columns: 1fr;
                gap: 7px;
            }

            .dash-reorder-item {
                padding: 7px 8px;
            }

            .dash-quick-strip {
                display: block;
                padding: 6px 8px;
            }

            .dash-quick-head {
                margin-bottom: 8px;
                align-items: center;
            }

            .dash-quick-title {
                font-size: 14px;
            }

            .dash-quick-sub {
                font-size: 10px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .dash-quick-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }

            .dash-quick-item {
                min-height: 46px;
                padding: 6px 7px;
                gap: 6px;
                box-shadow: 0 5px 10px rgba(15, 23, 42, 0.08);
                border-radius: 10px;
            }

            .dash-quick-icon {
                width: 23px;
                height: 23px;
                border-radius: 6px;
            }

            .dash-quick-icon svg {
                width: 12px;
                height: 12px;
            }

            .dash-quick-copy strong {
                font-size: 10px;
                line-height: 1.15;
            }

            .dash-quick-item span small {
                display: none;
            }

            .dash-list-head {
                padding: 10px 12px;
            }

            .dash-table-card {
                border-radius: 16px;
                clip-path: inset(0 round 16px);
            }

            .dash-table-card .dash-list-head {
                border-top-left-radius: 16px;
                border-top-right-radius: 16px;
            }

            .dash-list-head h2 {
                font-size: 16px;
            }

            .dash-list-link {
                padding: 6px 10px;
                font-size: 11px;
            }

            .dash-table-head {
                grid-template-columns: minmax(0, 1fr) auto;
                padding: 7px 10px;
                font-size: 9px;
                gap: 8px;
            }

            .dash-table-head span:nth-child(2) {
                display: none;
            }

            .dash-table-wrap {
                margin: 8px 10px 10px;
                border-radius: 12px;
            }

            .dash-row {
                padding: 10px 12px;
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .dash-row-title {
                font-size: 12px;
            }

            .dash-row-meta,
            .dash-empty {
                font-size: 10px;
            }

            .dash-row-icon {
                width: 30px;
                height: 30px;
                border-radius: 9px;
            }

            .dash-row-icon svg {
                width: 14px;
                height: 14px;
            }

            .dash-row-mid {
                display: none;
            }

            .dash-row-end {
                gap: 5px;
                padding-left: 4px;
            }

            .dash-tag {
                min-height: 20px;
                padding: 3px 7px;
                font-size: 9px;
            }

            .dash-amount {
                font-size: 12px;
            }

            .dash-row-arrow {
                width: 12px;
                height: 12px;
            }

            #emailVerifyBanner {
                flex-direction: column;
                padding: 12px 14px !important;
            }

            #emailVerifyBanner input,
            #emailVerifyBanner button {
                max-width: none !important;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .dash-root {
                padding-top: 8px;
            }

            .dash-header .page-actions .dash-command-mobile-trigger {
                width: 36px;
                min-width: 36px;
                height: 36px;
            }

            .dash-command-layer {
                padding-top: 64px;
            }

            .dash-command-input-row {
                gap: 8px;
                padding: 9px 10px;
            }

            .dash-command-helper {
                padding: 7px 10px;
                font-size: 11px;
            }

            .dash-header .page-title {
                font-size: 18px;
            }

            .dash-col-revenue .dash-kpi-value,
            .dash-col-profit .dash-kpi-value,
            .dash-mini-value {
                font-size: 20px;
            }

            .dash-shop-actions .dash-btn,
            .dash-header .page-actions .btn {
                font-size: 11.5px;
                padding-inline: 14px;
            }

            .dash-quick-strip {
                padding: 5px 6px;
            }

            .dash-quick-list {
                gap: 5px;
            }

            .dash-quick-item {
                min-height: 40px;
                padding: 5px 6px;
                gap: 5px;
                border-radius: 9px;
            }

            .dash-quick-icon {
                width: 20px;
                height: 20px;
                border-radius: 5px;
            }

            .dash-quick-icon svg {
                width: 10px;
                height: 10px;
            }

            .dash-quick-copy strong {
                font-size: 9px;
                line-height: 1.1;
            }

            .dash-quick-sub {
                display: none;
            }
        }
    </style>

    <script>
        (function() {
            if (/Windows/i.test(window.navigator.userAgent)) {
                document.documentElement.classList.add('os-windows');
            }
        })();
    </script>

    @php
        $dashCommandPages = [
            ['label' => 'Dashboard', 'sub' => 'Overview and KPIs', 'url' => route('dashboard'), 'keywords' => 'home overview metrics kpi'],
            ['label' => 'Start Sale (POS)', 'sub' => 'Open billing counter', 'url' => route('pos.index'), 'keywords' => 'pos billing sale invoice'],
            ['label' => 'Customers', 'sub' => 'Customer directory', 'url' => route('customers.index'), 'keywords' => 'customer crm contacts'],
            ['label' => 'Invoices', 'sub' => 'Sales invoices', 'url' => route('invoices.index'), 'keywords' => 'invoice sales billing'],
            ['label' => 'Stock / Items', 'sub' => 'Inventory list', 'url' => route('inventory.items.index'), 'keywords' => 'items stock inventory product'],
            ['label' => 'Quick Bills', 'sub' => 'Fast billing register', 'url' => route('quick-bills.index'), 'keywords' => 'quick bill register'],
            ['label' => 'Cash Ledger', 'sub' => 'Cashbook entries', 'url' => route('cashbook.index'), 'keywords' => 'cashbook cash ledger'],
            ['label' => 'Daily Closing', 'sub' => 'Closing summary', 'url' => route('report.closing'), 'keywords' => 'closing report day end'],
            ['label' => 'Repairs', 'sub' => 'Repair workflow', 'url' => route('repairs.index'), 'keywords' => 'repair service jobs'],
            ['label' => 'Settings', 'sub' => 'Shop configuration', 'url' => route('settings.edit'), 'keywords' => 'settings config preferences'],
        ];

        if (auth()->user()->shop?->isRetailer()) {
            $dashCommandPages[] = ['label' => 'WhatsApp Catalog', 'sub' => 'Share catalog links', 'url' => route('catalog.index'), 'keywords' => 'catalog whatsapp share'];
            $dashCommandPages[] = ['label' => 'Vendors', 'sub' => 'Supplier management', 'url' => route('vendors.index'), 'keywords' => 'vendors suppliers'];
            $dashCommandPages[] = ['label' => 'GST Report', 'sub' => 'Tax summary', 'url' => route('report.gst'), 'keywords' => 'gst tax report'];
        }

        $dashCommandTypes = ['customers', 'invoices', 'items', 'products', 'quick-bills'];
        if (auth()->user()->shop?->isRetailer()) {
            $dashCommandTypes[] = 'vendors';
        }
    @endphp

    <x-page-header
        title="Dashboard"
        :subtitle="now()->format('l, d M Y')"
        class="dash-header"
    >
        <x-slot:actions>
            <button type="button" class="dash-command-desktop-trigger" data-dash-command-trigger aria-label="Search pages and records">
                <svg class="dash-command-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 1 0 4.74 13.31l4.22 4.21a.75.75 0 1 0 1.06-1.06l-4.2-4.22A7.5 7.5 0 0 0 10.5 3Zm-6 7.5a6 6 0 1 1 12 0 6 6 0 0 1-12 0Z"/></svg>
                <span class="dash-command-trigger-copy dash-command-trigger-copy-full">Search pages, customers, invoices, products...</span>
                <span class="dash-command-trigger-copy dash-command-trigger-copy-short">Search</span>
                <span class="dash-command-trigger-kbd">Ctrl+K</span>
            </button>

            <button type="button" class="dash-command-mobile-trigger" data-dash-command-trigger aria-label="Open search">
                <svg class="dash-command-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 1 0 4.74 13.31l4.22 4.21a.75.75 0 1 0 1.06-1.06l-4.2-4.22A7.5 7.5 0 0 0 10.5 3Zm-6 7.5a6 6 0 1 1 12 0 6 6 0 0 1-12 0Z"/></svg>
            </button>

            <a href="/pos" class="btn btn-primary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                <span class="dash-btn-label-full">Start Sale</span>
                <span class="dash-btn-label-short">Sale</span>
            </a>
            <a href="/repairs" class="btn btn-secondary btn-sm dash-mobile-float-target">
                <span class="dash-btn-label-full">Repairs</span>
                <span class="dash-btn-label-short">Repair</span>
            </a>
            <a href="/report/closing" class="btn btn-dark btn-sm dash-mobile-float-target">
                <span class="dash-btn-label-full">Daily Closing</span>
                <span class="dash-btn-label-short">Closing</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="dash-command-layer" data-dash-command-layer hidden>
        <button type="button" class="dash-command-backdrop" data-dash-command-close aria-label="Close search"></button>
        <section class="dash-command-card" role="dialog" aria-modal="true" aria-label="Dashboard search">
            <div class="dash-command-input-row">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="dash-command-input" data-dash-command-input placeholder="Search pages, customers, invoices, items..." autocomplete="off" spellcheck="false">
                <button type="button" class="dash-command-close" data-dash-command-close>Esc</button>
            </div>
            <div class="dash-command-helper" data-dash-command-helper>Jump to common pages. Type to search customers, invoices, products, and more.</div>
            <ul class="dash-command-results" data-dash-command-results></ul>
        </section>
    </div>

    <script>
        (() => {
            const layer = document.querySelector('[data-dash-command-layer]');
            if (!layer) return;

            const triggers = Array.from(document.querySelectorAll('[data-dash-command-trigger]'));
            const closeControls = Array.from(document.querySelectorAll('[data-dash-command-close]'));
            const input = layer.querySelector('[data-dash-command-input]');
            const helper = layer.querySelector('[data-dash-command-helper]');
            const resultsList = layer.querySelector('[data-dash-command-results]');

            const endpoint = @json(route('search.suggestions'));
            const pageIndex = @json($dashCommandPages);
            const searchTypes = @json($dashCommandTypes);

            const typeLabel = {
                customers: 'Customer',
                invoices: 'Invoice',
                items: 'Item',
                products: 'Product',
                vendors: 'Vendor',
                'quick-bills': 'Quick Bill',
            };

            let debounceId = null;
            let activeIndex = -1;
            let currentResults = [];
            let searchVersion = 0;

            const normalize = (value) => String(value || '').toLowerCase().trim();

            const pageMatches = (query) => {
                const q = normalize(query);
                const toResult = (page) => ({
                    type: 'Page',
                    label: page.label,
                    sub: page.sub || '',
                    url: page.url,
                });

                if (!q) {
                    return pageIndex.slice(0, 8).map(toResult);
                }

                return pageIndex
                    .filter((page) => normalize(`${page.label} ${page.sub || ''} ${page.keywords || ''}`).includes(q))
                    .slice(0, 8)
                    .map(toResult);
            };

            const clearResults = () => {
                resultsList.innerHTML = '';
                activeIndex = -1;
            };

            const setHelper = (text) => {
                helper.textContent = text;
            };

            const setActive = (index) => {
                const rows = Array.from(resultsList.querySelectorAll('.dash-command-item'));
                activeIndex = rows.length ? Math.max(0, Math.min(index, rows.length - 1)) : -1;

                rows.forEach((row, i) => row.classList.toggle('is-active', i === activeIndex));
                if (activeIndex >= 0) {
                    rows[activeIndex].scrollIntoView({ block: 'nearest' });
                }
            };

            const renderResults = () => {
                clearResults();

                if (!currentResults.length) {
                    const empty = document.createElement('li');
                    empty.className = 'dash-command-helper';
                    empty.textContent = 'No matches found.';
                    resultsList.appendChild(empty);
                    return;
                }

                const fragment = document.createDocumentFragment();

                currentResults.forEach((result, index) => {
                    const li = document.createElement('li');
                    const link = document.createElement('a');

                    link.href = result.url;
                    link.className = 'dash-command-item';
                    link.dataset.index = String(index);

                    const copyWrap = document.createElement('span');
                    const title = document.createElement('span');
                    const sub = document.createElement('span');
                    const type = document.createElement('span');

                    title.className = 'dash-command-item-title';
                    title.textContent = result.label;
                    copyWrap.appendChild(title);

                    if (result.sub) {
                        sub.className = 'dash-command-item-sub';
                        sub.textContent = result.sub;
                        copyWrap.appendChild(sub);
                    }

                    type.className = 'dash-command-item-type';
                    type.textContent = result.type;

                    link.appendChild(copyWrap);
                    link.appendChild(type);

                    link.addEventListener('mouseenter', () => setActive(index));

                    li.appendChild(link);
                    fragment.appendChild(li);
                });

                resultsList.appendChild(fragment);
            };

            const fetchType = async (type, query, version) => {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('type', type);
                url.searchParams.set('q', query);

                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                    },
                });

                if (!response.ok || version !== searchVersion) {
                    return [];
                }

                const rows = await response.json();
                if (!Array.isArray(rows)) {
                    return [];
                }

                return rows
                    .filter((row) => row && row.url && row.label)
                    .map((row) => ({
                        type: typeLabel[type] || 'Result',
                        label: row.label,
                        sub: row.sub || '',
                        url: row.url,
                    }));
            };

            const runSearch = async () => {
                const query = normalize(input.value);
                const pages = pageMatches(query);
                const version = ++searchVersion;

                if (!query) {
                    currentResults = pages;
                    setHelper('Jump to common pages. Type to search customers, invoices, products, and more.');
                    renderResults();
                    return;
                }

                setHelper('Searching...');

                const grouped = await Promise.all(
                    searchTypes.map((type) => fetchType(type, query, version).catch(() => []))
                );

                if (version !== searchVersion) {
                    return;
                }

                currentResults = [...pages, ...grouped.flat()].slice(0, 24);
                setHelper(currentResults.length ? 'Use arrow keys and Enter to open a result.' : 'No matches found.');
                renderResults();
            };

            const queueSearch = () => {
                window.clearTimeout(debounceId);
                debounceId = window.setTimeout(runSearch, 170);
            };

            const openPalette = () => {
                layer.hidden = false;
                document.body.classList.add('dash-command-open');
                queueSearch();
                window.requestAnimationFrame(() => {
                    input.focus();
                    input.select();
                });
            };

            const closePalette = () => {
                layer.hidden = true;
                document.body.classList.remove('dash-command-open');
                activeIndex = -1;
            };

            triggers.forEach((trigger) => {
                trigger.addEventListener('click', openPalette);
            });

            closeControls.forEach((control) => {
                control.addEventListener('click', closePalette);
            });

            input.addEventListener('input', queueSearch);

            input.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setActive(activeIndex + 1);
                    return;
                }

                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setActive(activeIndex <= 0 ? currentResults.length - 1 : activeIndex - 1);
                    return;
                }

                if (event.key === 'Enter') {
                    if (!currentResults.length) {
                        return;
                    }

                    event.preventDefault();
                    const target = currentResults[activeIndex >= 0 ? activeIndex : 0];
                    if (target && target.url) {
                        window.location.href = target.url;
                    }
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    closePalette();
                }
            });

            document.addEventListener('keydown', (event) => {
                const isPaletteShortcut = (event.ctrlKey || event.metaKey) && normalize(event.key) === 'k';
                if (!isPaletteShortcut) {
                    return;
                }

                event.preventDefault();
                if (layer.hidden) {
                    openPalette();
                } else {
                    closePalette();
                }
            });

            layer.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }
                if (target.matches('[data-dash-command-close]')) {
                    closePalette();
                }
            });
        })();
    </script>

    <div class="content-inner dash-root dash-loading space-y-3" data-dashboard-shell>
        <div
            x-data="{ mobileFabOpen: false }"
            class="dash-mobile-fab"
            @keydown.escape.window="mobileFabOpen = false"
        >
            <div class="dash-mobile-fab-shell" x-bind:class="{ 'is-open': mobileFabOpen }" @click.outside="mobileFabOpen = false">
                <nav class="dash-mobile-fab-nav" aria-label="Quick mobile actions">
                    <a href="/report/closing" class="dash-mobile-fab-action" @click="mobileFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14213d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17v-2m3 2v-4m3 4v-6"/><path d="M3 3h18v18H3z"/></svg>
                        <span>Daily Closing</span>
                    </a>
                    <a href="/repairs" class="dash-mobile-fab-action" @click="mobileFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14213d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        <span>Repairs</span>
                    </a>
                </nav>

                <button type="button" class="dash-mobile-fab-toggle" x-on:click="mobileFabOpen = !mobileFabOpen" x-bind:aria-expanded="mobileFabOpen.toString()" aria-label="Toggle quick mobile actions">
                    <span class="dash-mobile-fab-bars" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </div>
        </div>

        <x-app-alerts class="mb-1" />

        @if(!auth()->user()->email_verified_at)
        {{-- ══ EMAIL VERIFICATION BANNER ══════════════════════════════ --}}
        <div id="emailVerifyBanner" class="dash-email-verify">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#b45309" stroke-width="2" viewBox="0 0 24 24" class="dash-email-verify-icon"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.8 19.8 0 0 1 1.62 3.38 2 2 0 0 1 3.6 1.21h3a2 2 0 0 1 2 1.72 12.8 12.8 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.07 6.07l.91-.91a2 2 0 0 1 2.11-.45 12.8 12.8 0 0 0 2.81.7A2 2 0 0 1 21.79 16.92z"/></svg>
            <div class="dash-email-verify-copy">
                <div class="dash-email-verify-title">
                    Add your email to enable password recovery
                </div>
                <div class="dash-email-verify-subtitle">
                    If you ever forget your password, a verified email is the only way to reset it.
                </div>

                {{-- STEP 1: Enter email --}}
                <div id="emailStep1">
                    <div class="dash-email-verify-row">
                        <input type="email" id="verifyEmailInput" placeholder="your@email.com"
                            class="dash-email-input">
                        <button onclick="sendEmailOtp()" id="sendOtpBtn"
                            class="dash-email-btn dash-email-btn-send">
                            Send Code
                        </button>
                        <button onclick="dismissEmailBanner()" title="Dismiss"
                            class="dash-email-btn dash-email-btn-later">
                            Later
                        </button>
                    </div>
                    <div id="emailStep1Error" class="dash-email-step-error"></div>
                </div>

                {{-- STEP 2: Enter OTP (hidden initially) --}}
                <div id="emailStep2" class="dash-email-step2">
                    <div class="dash-email-step2-note">
                        ✓ Code sent to <strong id="sentToEmail"></strong> — check your inbox.
                    </div>
                    <div class="dash-email-verify-row">
                        <input type="text" id="verifyOtpInput" placeholder="6-digit code"
                            maxlength="6" inputmode="numeric"
                            class="dash-email-otp-input">
                        <button onclick="verifyEmailOtp()" id="verifyOtpBtn"
                            class="dash-email-btn dash-email-btn-verify">
                            Verify
                        </button>
                        <button onclick="resendOtp()" id="resendBtn"
                            class="dash-email-btn dash-email-btn-resend">
                            Resend
                        </button>
                        <span id="otpCountdown" class="dash-email-countdown"></span>
                    </div>
                    <div id="emailStep2Error" class="dash-email-step-error"></div>
                </div>

                {{-- SUCCESS (hidden initially) --}}
                <div id="emailVerifySuccess" class="dash-email-success">
                    ✓ Email verified! Your account is now secured with password recovery.
                </div>
            </div>
        </div>
        @endif
        {{-- ════════════════════════════════════════════════════════════ --}}

        <script>
        (function() {
            const CSRF       = '{{ csrf_token() }}';
            const SEND_URL   = '{{ route("email.otp.send") }}';
            const VERIFY_URL = '{{ route("email.otp.verify") }}';
            const RESEND_URL = '{{ route("email.otp.resend") }}';
            let countdownTimer = null;

            function post(url, body) {
                return fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                });
            }

            function showError(id, msg) {
                const el = document.getElementById(id);
                el.textContent = msg; el.style.display = 'block';
            }
            function clearError(id) {
                const el = document.getElementById(id);
                if (el) { el.textContent = ''; el.style.display = 'none'; }
            }
            function setLoading(btnId, loading) {
                const btn = document.getElementById(btnId);
                if (btn) btn.disabled = loading;
            }

            function startCountdown(sec) {
                clearInterval(countdownTimer);
                const el = document.getElementById('otpCountdown');
                let s = sec;
                countdownTimer = setInterval(() => {
                    el.textContent = 'Expires in ' + s + 's';
                    if (--s < 0) { clearInterval(countdownTimer); el.textContent = 'Code expired — resend.'; }
                }, 1000);
            }

            window.sendEmailOtp = async function() {
                clearError('emailStep1Error');
                const email = document.getElementById('verifyEmailInput').value.trim();
                if (!email) { showError('emailStep1Error', 'Please enter your email address.'); return; }
                setLoading('sendOtpBtn', true);

                const res = await post(SEND_URL, { email });
                const data = await res.json();
                setLoading('sendOtpBtn', false);

                if (!res.ok) {
                    const msg = data.errors?.email?.[0] || data.message || 'Failed to send code.';
                    showError('emailStep1Error', msg); return;
                }

                document.getElementById('sentToEmail').textContent = email;
                document.getElementById('emailStep1').style.display = 'none';
                document.getElementById('emailStep2').style.display = 'block';
                startCountdown(600);
                startResendCooldown(60); // disable Resend for 60s after first send
            };

            window.verifyEmailOtp = async function() {
                clearError('emailStep2Error');
                const otp = document.getElementById('verifyOtpInput').value.trim();
                if (otp.length !== 6) { showError('emailStep2Error', 'Please enter the 6-digit code.'); return; }
                setLoading('verifyOtpBtn', true);

                const res = await post(VERIFY_URL, { otp });
                const data = await res.json();
                setLoading('verifyOtpBtn', false);

                if (!res.ok) {
                    const msg = data.errors?.otp?.[0] || data.message || 'Verification failed.';
                    showError('emailStep2Error', msg); return;
                }

                clearInterval(countdownTimer);
                document.getElementById('emailStep2').style.display = 'none';
                document.getElementById('emailVerifySuccess').style.display = 'block';
                setTimeout(() => {
                    const b = document.getElementById('emailVerifyBanner');
                    if (b) b.style.display = 'none';
                }, 3000);
            };

            let resendTimer = null;
            function startResendCooldown(sec) {
                const btn = document.getElementById('resendBtn');
                if (!btn) return;
                clearInterval(resendTimer);
                btn.disabled = true;
                let s = sec;
                btn.textContent = 'Resend (' + s + 's)';
                resendTimer = setInterval(() => {
                    s--;
                    if (s <= 0) {
                        clearInterval(resendTimer);
                        btn.disabled = false;
                        btn.textContent = 'Resend';
                    } else {
                        btn.textContent = 'Resend (' + s + 's)';
                    }
                }, 1000);
            }

            window.resendOtp = async function() {
                clearError('emailStep2Error');
                const res = await post(RESEND_URL, {});
                const data = await res.json();
                if (!res.ok) {
                    const msg = data.errors?.email?.[0] || data.message || 'Failed to resend.';
                    showError('emailStep2Error', msg); return;
                }
                showError('emailStep2Error', '✓ New code sent!');
                document.getElementById('emailStep2Error').style.color = '#15803d';
                startCountdown(600);
                startResendCooldown(60);
            };

            window.dismissEmailBanner = function() {
                sessionStorage.setItem('emailBannerDismissed', '1');
                const b = document.getElementById('emailVerifyBanner');
                if (b) b.style.display = 'none';
            };

            // Auto-dismiss if user dismissed in this session
            if (sessionStorage.getItem('emailBannerDismissed')) {
                const b = document.getElementById('emailVerifyBanner');
                if (b) b.style.display = 'none';
            }

            // Auto-focus OTP input on step 2 show
            const step2 = document.getElementById('emailStep2');
            if (step2) {
                const observer = new MutationObserver(() => {
                    if (step2.style.display !== 'none') {
                        document.getElementById('verifyOtpInput')?.focus();
                    }
                });
                observer.observe(step2, { attributes: true, attributeFilter: ['style'] });
            }
        })();
        </script>

        @php
            $shop = auth()->user()->shop;
            $shopLogo = $shop?->logo_path ? asset('storage/' . ltrim($shop->logo_path, '/')) : null;
            $ownerName = trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? ''));
            $shopType = ($shop?->shop_type ?? '') === 'manufacturer' ? 'Manufacturer' : 'Retail';
            $shopInitials = '';
            if ($shop?->name) {
                $parts = preg_split('/\s+/', trim($shop->name));
                $shopInitials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
            }
        @endphp

        <div class="dash-top-grid" style="grid-template-columns: repeat(4, 1fr); gap: 16px;">
            <div class="dash-block dash-col-quick dash-quick-strip" style="grid-column: span 4;">
                <div class="dash-quick-list">
                    <a href="/pos" class="dash-quick-item dash-quick-sale">
                        <span class="dash-quick-icon"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                        <span class="dash-quick-copy"><strong>Start Sale</strong><small>Open POS checkout</small></span>
                    </a>
                    <a href="/customers/create" class="dash-quick-item dash-quick-customer">
                        <span class="dash-quick-icon"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg></span>
                        <span class="dash-quick-copy"><strong>New Customer</strong><small>Create customer profile</small></span>
                    </a>
                    <a href="/inventory/items/create" class="dash-quick-item dash-quick-itemadd">
                        <span class="dash-quick-icon"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
                        <span class="dash-quick-copy"><strong>Add Inventory</strong><small>Create stock item</small></span>
                    </a>
                    <a href="/report/closing" class="dash-quick-item dash-quick-closing">
                        <span class="dash-quick-icon"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17v-2m3 2v-4m3 4v-6"/><path d="M3 3h18v18H3z"/></svg></span>
                        <span class="dash-quick-copy"><strong>Close Day</strong><small>Run day-end report</small></span>
                    </a>
                </div>
            </div>

            <div class="dash-block dash-shop-card" style="padding: 18px;">
                <div class="dash-shop-card-head" style="gap: 6px;">
                    <div class="dash-shop-brand" style="gap: 6px;">
                        <div class="dash-logo-box" style="width: 40px; height: 40px; font-size: 12px;">
                            @if($shopLogo)
                                <img src="{{ $shopLogo }}" alt="{{ $shop?->name ?? 'Shop' }}">
                            @else
                                <span>{{ $shopInitials ?: 'SH' }}</span>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="dash-shop-meta-label" style="font-size: 9px;">Shop Profile</p>
                            <h2 class="dash-shop-name truncate" style="font-size: 16px;">{{ $shop?->name ?? 'Your Shop' }}</h2>
                        </div>
                    </div>
                    <span class="dash-badge">{{ $shopType }}</span>
                </div>

                <div class="dash-shop-info">
                    <p class="dash-meta" style="font-size: 11px;"><strong>Owner</strong>{{ $ownerName !== '' ? $ownerName : ($shop?->owner_mobile ?? '—') }}</p>
                    <p class="dash-meta" style="font-size: 11px;"><strong>Phone</strong>{{ $shop?->phone ?? '—' }}</p>
                    @if($shop?->city || $shop?->state)
                        <p class="dash-meta" style="font-size: 11px;"><strong>Location</strong>{{ $shop?->city }}{{ $shop?->state ? ', ' . $shop->state : '' }}</p>
                    @endif
                </div>

                <div class="dash-shop-actions" style="gap: 6px;">
                    <a href="{{ route('settings.edit', ['tab' => 'shop']) }}" class="dash-btn dash-btn-muted" style="min-height: 32px; font-size: 11px;">Edit Shop</a>
                    <a href="/inventory/items" class="dash-btn dash-btn-primary" style="min-height: 32px; font-size: 11px;">View Inventory</a>
                </div>
            </div>
            <div class="dash-block dash-top-kpi dash-top-kpi-revenue" style="padding: 18px;">
                <div class="dash-top-kpi-head">
                    <span class="dash-top-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><text x="6" y="17" font-size="14">₹</text></svg>
                    </span>
                    <p class="dash-top-kpi-meta">Today's Metal Rates</p>
                </div>
                <div class="dash-kpi-value dash-skel dash-top-kpi-value" style="font-size: 16px; font-weight: 500; color: #8f5c00;">
                    @php
                        $todayRate = app('App\\Services\\ShopPricingService')->currentDailyRate($shop);
                    @endphp
                    @if($todayRate)
                        <div><span style="font-weight: 600;">Gold 24K:</span> ₹{{ number_format((float) $todayRate->gold_24k_rate_per_gram, 4) }}/g</div>
                        <div style="margin-top: 2px;"><span style="font-weight: 600;">Silver 999:</span> ₹{{ number_format((float) $todayRate->silver_999_rate_per_gram, 4) }}/g</div>
                    @else
                        <div>No rates set for today.</div>
                    @endif
                </div>
                <div class="dash-top-kpi-foot">
                    <p class="dash-top-kpi-title">24K Gold & Silver</p>
                    <p class="dash-top-kpi-note">User provided rates</p>
                </div>
            </div>

            @if($isRetailer ?? false)
                <div class="dash-block dash-top-kpi dash-top-kpi-revenue" style="padding: 18px;">
                    <div class="dash-top-kpi-head">
                        <span class="dash-top-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 6h18v12H3z"></path>
                                <path d="M3 10h18"></path>
                                <circle cx="8" cy="15" r="1"></circle>
                            </svg>
                        </span>
                        <p class="dash-top-kpi-meta">{{ $invoicesToday }} {{ \Illuminate\Support\Str::plural('sale', $invoicesToday) }} today</p>
                    </div>
                    <div class="dash-kpi-value dash-skel dash-top-kpi-value">₹{{ number_format($todaysRevenue ?? 0, 2) }}</div>
                    <div class="dash-top-kpi-foot">
                        <p class="dash-top-kpi-title">Today's Revenue</p>
                        <p class="dash-top-kpi-note">Gross billed</p>
                    </div>
                </div>
                <div class="dash-block dash-top-kpi dash-top-kpi-profit {{ ($todaysProfit ?? 0) < 0 ? 'is-loss' : '' }}" style="padding: 18px;">
                    <div class="dash-top-kpi-head">
                        <span class="dash-top-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 3v18h18"></path>
                                <path d="M7 14l4-4 3 3 4-5"></path>
                            </svg>
                        </span>
                        <p class="dash-top-kpi-meta">{{ ($todaysProfit ?? 0) >= 0 ? 'Net gain today' : 'Net loss today' }}</p>
                    </div>
                    <div class="dash-kpi-value dash-skel dash-top-kpi-value" style="color: {{ ($todaysProfit ?? 0) >= 0 ? '#14284b' : '#b42318' }};">₹{{ number_format($todaysProfit ?? 0, 2) }}</div>
                    <div class="dash-top-kpi-foot">
                        <p class="dash-top-kpi-title">Today's Profit</p>
                        <p class="dash-top-kpi-note">Price - Cost</p>
                    </div>
                </div>
            @endif
        </div>

        @php
            $warnings = [];
            if ($openRepairs > 8) $warnings[] = 'Repair backlog is high (' . $openRepairs . ')';
            if ($stock < $lowStockThreshold) $warnings[] = 'Low stock: only ' . $stock . ' item(s) in stock';
            if (($isRetailer ?? false) && ($overdueEmis ?? 0) > 0) $warnings[] = $overdueEmis . ' EMI plan(s) are overdue';
        @endphp

        @if(count($warnings) > 0)
            <div class="dash-warnings">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8f5300" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                @foreach($warnings as $msg)
                    <span class="dash-warning-chip">{{ $msg }}</span>
                @endforeach
            </div>
        @endif

        <div class="dash-mini-grid">
            <section class="dash-block dash-mini dash-mini-dark dash-mini-kpi" aria-labelledby="dash-mini-label-invoices-today">
                <div class="dash-mini-kpi-head">
                    <span class="dash-mini-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="8" y1="13" x2="16" y2="13"></line>
                        </svg>
                    </span>
                    <p class="dash-mini-kpi-meta">Today</p>
                </div>
                <h3 class="dash-mini-value dash-skel">{{ $invoicesToday }}</h3>
                <div class="dash-mini-kpi-foot">
                    <p class="dash-mini-kpi-title" id="dash-mini-label-invoices-today">Invoices Today</p>
                </div>
            </section>

            <section class="dash-block dash-mini dash-mini-dark dash-mini-kpi" aria-labelledby="dash-mini-label-open-repairs">
                <div class="dash-mini-kpi-head">
                    <span class="dash-mini-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                        </svg>
                    </span>
                    <p class="dash-mini-kpi-meta">Pending repairs</p>
                </div>
                <h3 class="dash-mini-value dash-skel">{{ $openRepairs }}</h3>
                <div class="dash-mini-kpi-foot">
                    <p class="dash-mini-kpi-title" id="dash-mini-label-open-repairs">Open Repairs</p>
                </div>
            </section>

            <section class="dash-block dash-mini dash-mini-dark dash-mini-kpi" aria-labelledby="dash-mini-label-stock">
                <div class="dash-mini-kpi-head">
                    <span class="dash-mini-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line>
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                        </svg>
                    </span>
                    <p class="dash-mini-kpi-meta">{{ $stock < $lowStockThreshold ? 'Low stock' : 'Healthy stock' }}</p>
                </div>
                <h3 class="dash-mini-value dash-skel">{{ number_format($stock) }}</h3>
                <div class="dash-mini-kpi-foot">
                    <p class="dash-mini-kpi-title" id="dash-mini-label-stock">Items In Stock</p>
                </div>
            </section>

            <section class="dash-block dash-mini dash-mini-dark dash-mini-kpi" aria-labelledby="dash-mini-label-customers">
                <div class="dash-mini-kpi-head">
                    <span class="dash-mini-kpi-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        </svg>
                    </span>
                    <p class="dash-mini-kpi-meta">Customer base</p>
                </div>
                <h3 class="dash-mini-value dash-skel">{{ number_format($customerCount) }}</h3>
                <div class="dash-mini-kpi-foot">
                    <p class="dash-mini-kpi-title" id="dash-mini-label-customers">Customers</p>
                </div>
            </section>

            @if($isRetailer ?? false)
                <section class="dash-block dash-mini dash-mini-dark dash-mini-kpi" aria-labelledby="dash-mini-label-overdue-emis">
                    <div class="dash-mini-kpi-head">
                        <span class="dash-mini-kpi-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                <line x1="12" y1="9" x2="12" y2="13"></line>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                        </span>
                        <p class="dash-mini-kpi-meta">{{ ($overdueEmis ?? 0) > 0 ? 'Needs follow-up' : 'On track' }}</p>
                    </div>
                    <h3 class="dash-mini-value dash-skel">{{ $overdueEmis ?? 0 }}</h3>
                    <div class="dash-mini-kpi-foot">
                        <p class="dash-mini-kpi-title" id="dash-mini-label-overdue-emis">Overdue EMIs</p>
                        <a href="{{ route('installments.index', ['status' => 'active']) }}" class="dash-mini-kpi-link">Open</a>
                    </div>
                </section>
            @endif
        </div>

        @if(($reorderAlertCount ?? 0) > 0)
            <div class="dash-block dash-reorder-widget">
                <div class="dash-reorder-head">
                    <h2 class="dash-reorder-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Low Stock / Reorder Alerts
                    </h2>
                    <a href="{{ route('reorder.index') }}" class="dash-reorder-link">
                        <span>Open Alerts</span>
                        <strong>{{ $reorderAlertCount }}</strong>
                    </a>
                </div>
                <div class="dash-reorder-list">
                    @foreach(($reorderAlerts ?? collect()) as $alert)
                        <div class="dash-reorder-item">
                            <div class="dash-reorder-item-head">
                                {{ $alert['category'] }}
                                @if(!empty($alert['sub_category']))
                                    · {{ $alert['sub_category'] }}
                                @endif
                            </div>
                            <div class="dash-reorder-item-meta">
                                <strong>{{ $alert['current_stock'] }}</strong> in stock · min {{ $alert['threshold'] }}
                                @if(!empty($alert['vendor_name']))
                                    · {{ $alert['vendor_name'] }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if(($reorderAlertCount ?? 0) > count($reorderAlerts ?? []))
                        <span class="dash-reorder-more">+{{ ($reorderAlertCount ?? 0) - count($reorderAlerts ?? []) }} more alerts</span>
                    @endif
                </div>
            </div>
        @endif

        <div class="dash-main-grid">
            <div class="dash-block dash-panel-pad">
                @php
                    $formatCompactRupee = static function (float $amount): string {
                        $abs = abs($amount);
                        if ($abs >= 10000000) return '₹' . number_format($amount / 10000000, 1) . 'Cr';
                        if ($abs >= 100000) return '₹' . number_format($amount / 100000, 1) . 'L';
                        if ($abs >= 1000) return '₹' . number_format($amount / 1000, 1) . 'k';
                        return '₹' . number_format($amount, 0);
                    };

                    $trendSeries = collect($trendData ?? [])
                        ->map(function ($row) {
                            return [
                                'date' => $row['date'] ?? now()->toDateString(),
                                'label' => $row['label'] ?? '',
                                'count' => (int) ($row['count'] ?? 0),
                                'revenue' => (float) ($row['revenue'] ?? 0),
                                'profit' => (float) ($row['profit'] ?? 0),
                            ];
                        })
                        ->values();

                    if ($trendSeries->isEmpty()) {
                        for ($i = 6; $i >= 0; $i--) {
                            $trendSeries->push([
                                'date' => now()->subDays($i)->toDateString(),
                                'label' => now()->subDays($i)->format('D'),
                                'count' => 0,
                                'revenue' => 0.0,
                                'profit' => 0.0,
                            ]);
                        }
                    }

                    $maxCount = (int) ($trendSeries->max('count') ?? 0);
                    $countStep = max(1, (int) ceil(($maxCount ?: 1) / 4));
                    $countYMax = $countStep * 4;
                    $countTicks = [$countYMax, $countStep * 3, $countStep * 2, $countStep, 0];

                    $maxRevenue = (float) ($trendSeries->max('revenue') ?? 0);
                    $maxProfitAbs = max(
                        abs((float) ($trendSeries->min('profit') ?? 0)),
                        abs((float) ($trendSeries->max('profit') ?? 0))
                    );
                    $financeYMax = max($maxRevenue, $maxProfitAbs, 1.0);
                    $financeStep = $financeYMax / 4;
                    $financeTicks = [
                        $financeStep * 4,
                        $financeStep * 3,
                        $financeStep * 2,
                        $financeStep,
                        0,
                    ];

                    $trendCountTotal = (int) $trendSeries->sum('count');
                    $trendCountAvg = round($trendCountTotal / max(1, $trendSeries->count()), 1);
                    $trendCountPeak = $trendSeries->sortByDesc('count')->first();

                    $trendRevenueTotal = (float) $trendSeries->sum('revenue');
                    $trendRevenueAvg = $trendRevenueTotal / max(1, $trendSeries->count());
                    $trendProfitTotal = (float) $trendSeries->sum('profit');
                    $trendProfitAvg = $trendProfitTotal / max(1, $trendSeries->count());
                    $trendRevenuePeak = $trendSeries->sortByDesc('revenue')->first();

                    $monthlySeries = collect($monthlyRevenueTrend ?? [])
                        ->map(function ($row) {
                            return [
                                'date' => $row['date'] ?? now()->toDateString(),
                                'label' => $row['label'] ?? now()->format('M j'),
                                'revenue' => (float) ($row['revenue'] ?? 0),
                                'invoice_count' => (int) ($row['invoice_count'] ?? 0),
                            ];
                        })
                        ->values();

                    if ($monthlySeries->isEmpty()) {
                        for ($i = 29; $i >= 0; $i--) {
                            $monthlySeries->push([
                                'date' => now()->subDays($i)->toDateString(),
                                'label' => now()->subDays($i)->format('M j'),
                                'revenue' => 0.0,
                                'invoice_count' => 0,
                            ]);
                        }
                    }

                    $monthlyTotalRevenue = (float) $monthlySeries->sum('revenue');
                    $monthlyAvgRevenue = $monthlyTotalRevenue / max(1, $monthlySeries->count());
                    $monthlyPeakRevenue = $monthlySeries->sortByDesc('revenue')->first();
                    $monthlyTopPeaks = $monthlySeries
                        ->sortByDesc('revenue')
                        ->take(3)
                        ->values();
                    $monthlyMaxRevenue = max(1.0, (float) ($monthlySeries->max('revenue') ?? 0));

                    $monthlyPointCount = max(1, $monthlySeries->count());
                    $monthlyMiddleIndex = (int) floor(($monthlyPointCount - 1) / 2);
                    $monthlyFirstLabel = $monthlySeries->first()['label'] ?? '';
                    $monthlyMiddleLabel = $monthlySeries[$monthlyMiddleIndex]['label'] ?? '';
                    $monthlyLastLabel = $monthlySeries->last()['label'] ?? '';
                    $monthlyPeakDate = $monthlyPeakRevenue['date'] ?? null;
                @endphp

                <div class="dash-chart-panel" x-data="{ metricView: 'count', setMetric(view) { if (this.metricView === view) return; const apply = () => { this.metricView = view; }; if (document.startViewTransition) { document.startViewTransition(apply); } else { apply(); } } }">
                        <div class="dash-chart-head">
                            <div>
                                <h2 class="dash-chart-title">7-Day Performance Snapshot</h2>
                                <p class="dash-chart-sub" x-show="metricView === 'count'">Invoice count trend for the last 7 days.</p>
                                <p class="dash-chart-sub" x-show="metricView === 'finance'">Revenue vs profit trend for the last 7 days.</p>
                            </div>
                            <div class="dash-chart-toggle" role="tablist" aria-label="Dashboard chart metric">
                                <button type="button" class="dash-toggle-btn" :class="{ 'is-active': metricView === 'count' }" @click="setMetric('count')">Invoice Count</button>
                                <button type="button" class="dash-toggle-btn" :class="{ 'is-active': metricView === 'finance' }" @click="setMetric('finance')">Revenue / Profit</button>
                            </div>
                        </div>

                        <div class="dash-chip-row" x-show="metricView === 'count'">
                            <span class="dash-chip">Total: <strong>{{ $trendCountTotal }}</strong></span>
                            <span class="dash-chip">Avg/day: <strong>{{ $trendCountAvg }}</strong></span>
                            <span class="dash-chip">Peak: <strong>{{ $trendCountPeak['label'] ?? '—' }} ({{ $trendCountPeak['count'] ?? 0 }})</strong></span>
                        </div>

                        <div class="dash-chip-row" x-show="metricView === 'finance'">
                            <span class="dash-chip">Revenue: <strong>{{ $formatCompactRupee($trendRevenueTotal) }}</strong></span>
                            <span class="dash-chip">Profit: <strong>{{ $formatCompactRupee($trendProfitTotal) }}</strong></span>
                            <span class="dash-chip">Peak Revenue: <strong>{{ $trendRevenuePeak['label'] ?? '—' }}</strong></span>
                        </div>

                        <div class="dash-chart-shell" x-show="metricView === 'count'">
                            <div class="dash-chart-grid">
                                <div class="dash-axis">
                                    @foreach($countTicks as $tick)
                                        <span>{{ $tick }}</span>
                                    @endforeach
                                </div>
                                <div class="dash-bars">
                                    @foreach($trendSeries as $day)
                                        @php
                                            $isToday = $day['date'] === now()->toDateString();
                                            $heightPct = $countYMax > 0 ? ($day['count'] / $countYMax) * 100 : 0;
                                        @endphp
                                        <div class="dash-col" title="{{ $day['count'] }} invoice(s) on {{ $day['date'] }}">
                                            <div class="dash-count">{{ $day['count'] }}</div>
                                            <div class="dash-rail">
                                                <div class="dash-bar {{ $isToday ? 'is-today' : '' }}" style="height: {{ max(4, $heightPct) }}%;"></div>
                                            </div>
                                            <div class="dash-day">{{ $day['label'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="dash-legend" aria-hidden="true">
                                <span class="dash-legend-item"><span class="dash-legend-dot neutral"></span>Other days</span>
                                <span class="dash-legend-item"><span class="dash-legend-dot today"></span>Today</span>
                            </div>
                            <p class="dash-note">Scale updates automatically based on the highest day in this 7-day window.</p>
                        </div>

                        <div class="dash-chart-shell" x-show="metricView === 'finance'">
                            <div class="dash-chart-grid">
                                <div class="dash-axis">
                                    @foreach($financeTicks as $tick)
                                        <span>{{ $formatCompactRupee($tick) }}</span>
                                    @endforeach
                                </div>
                                <div class="dash-bars dash-bars-finance">
                                    @foreach($trendSeries as $day)
                                        @php
                                            $revenue = (float) $day['revenue'];
                                            $profit = (float) $day['profit'];
                                            $revenueHeightPct = $financeYMax > 0 ? ($revenue / $financeYMax) * 100 : 0;
                                            $profitHeightPct = $financeYMax > 0 ? (abs($profit) / $financeYMax) * 100 : 0;
                                        @endphp
                                        <div class="dash-col" title="Revenue {{ number_format($revenue, 2) }} · Profit {{ number_format($profit, 2) }} on {{ $day['date'] }}">
                                            <div class="dash-finance-values">
                                                <span class="rev">{{ $formatCompactRupee($revenue) }}</span>
                                                <span class="pro {{ $profit < 0 ? 'is-loss' : '' }}">{{ $profit < 0 ? '-' : '' }}{{ $formatCompactRupee(abs($profit)) }}</span>
                                            </div>
                                            <div class="dash-rail-finance">
                                                <div class="dash-finance-bar revenue" style="height: {{ max(4, $revenueHeightPct) }}%;"></div>
                                                <div class="dash-finance-bar profit {{ $profit < 0 ? 'is-loss' : '' }}" style="height: {{ max(4, $profitHeightPct) }}%;"></div>
                                            </div>
                                            <div class="dash-day">{{ $day['label'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="dash-legend" aria-hidden="true">
                                <span class="dash-legend-item"><span class="dash-legend-dot revenue"></span>Revenue</span>
                                <span class="dash-legend-item"><span class="dash-legend-dot profit"></span>Profit</span>
                                <span class="dash-legend-item"><span class="dash-legend-dot loss"></span>Loss day</span>
                            </div>
                            <p class="dash-note">Revenue and profit bars are scaled to the highest value in this 7-day window.</p>
                        </div>
                    </div>
                </div>

            <div class="dash-block dash-panel-pad">
                <div class="dash-monthly-panel">
                        <div class="dash-monthly-shell">
                            <div class="dash-monthly-header">
                                <h3 class="dash-monthly-title">Monthly Revenue Trend · Last 30 Days</h3>
                                <p class="dash-monthly-sub">Bigger-picture daily revenue trend for owner-level decisions.</p>
                            </div>
                            <div class="dash-chip-row">
                                <span class="dash-chip">Total: <strong>{{ $formatCompactRupee($monthlyTotalRevenue) }}</strong></span>
                                <span class="dash-chip">Avg/day: <strong>{{ $formatCompactRupee($monthlyAvgRevenue) }}</strong></span>
                                <span class="dash-chip">Peak: <strong>{{ $monthlyPeakRevenue['label'] ?? '—' }} ({{ $formatCompactRupee((float) ($monthlyPeakRevenue['revenue'] ?? 0)) }})</strong></span>
                            </div>
                            <div class="dash-chart-shell">
                                <div class="dash-monthly-bars" style="--monthly-count: {{ max(1, $monthlySeries->count()) }};" role="img" aria-label="30-day monthly revenue trend bar chart">
                                    @foreach($monthlySeries as $day)
                                        @php
                                            $monthlyHeightPct = $monthlyMaxRevenue > 0 ? (((float) $day['revenue'] / $monthlyMaxRevenue) * 100) : 0;
                                            $isMonthlyPeak = ($day['date'] ?? null) === $monthlyPeakDate;
                                        @endphp
                                        <div class="dash-monthly-col" title="{{ $formatCompactRupee((float) $day['revenue']) }} on {{ $day['label'] }}">
                                            <div class="dash-monthly-bar-wrap">
                                                <div class="dash-monthly-bar {{ $isMonthlyPeak ? 'is-peak' : '' }}" style="height: {{ max(6, $monthlyHeightPct) }}%;"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="dash-monthly-axis">
                                    <span>{{ $monthlyFirstLabel }}</span>
                                    <span>{{ $monthlyMiddleLabel }}</span>
                                    <span>{{ $monthlyLastLabel }}</span>
                                </div>
                            </div>
                            <div class="dash-monthly-peaks">
                                @foreach($monthlyTopPeaks as $peak)
                                    <div class="dash-monthly-peak">
                                        <div class="dash-monthly-peak-label">{{ $peak['label'] }}</div>
                                        <div class="dash-monthly-peak-value">{{ $formatCompactRupee((float) $peak['revenue']) }}</div>
                                        <div class="dash-monthly-peak-meta">{{ (int) $peak['invoice_count'] }} {{ \Illuminate\Support\Str::plural('invoice', (int) $peak['invoice_count']) }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                </div>
            </div>
        </div>

        <div class="dash-list-grid">
            <div class="dash-block dash-table-card overflow-hidden dash-recent-card">
                <div class="dash-list-head">
                    <div class="dash-list-title">
                        <span class="dash-list-title-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="8" y1="13" x2="16" y2="13"></line>
                                <line x1="8" y1="17" x2="13" y2="17"></line>
                            </svg>
                        </span>
                        <h2>Recent Invoices</h2>
                    </div>
                    <a href="/invoices" class="dash-list-link">View all</a>
                </div>
                @if($recentInvoices->isEmpty())
                    <div class="dash-empty">No invoices yet.</div>
                @else
                    <div class="dash-table-wrap">
                        <div class="dash-table-head">
                            <span>Invoice</span>
                            <span>Date</span>
                            <span>Type</span>
                        </div>
                        <div class="dash-table-body">
                            @foreach($recentInvoices as $inv)
                                <a href="/invoices/{{ $inv->id }}" class="dash-row">
                                    <div class="dash-row-main">
                                        <span class="dash-row-icon invoice" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="8" y1="13" x2="16" y2="13"></line>
                                            </svg>
                                        </span>
                                        <div class="dash-row-copy">
                                            <div class="dash-row-title">{{ $inv->invoice_number }}</div>
                                            <div class="dash-row-meta">Issued {{ $inv->created_at->format('h:i A') }}</div>
                                        </div>
                                    </div>
                                    <div class="dash-row-mid">{{ $inv->created_at->format('d M') }}</div>
                                    <div class="dash-row-end">
                                        <span class="dash-tag invoice">Invoice</span>
                                        <svg class="dash-row-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="dash-block dash-table-card overflow-hidden dash-recent-card">
                <div class="dash-list-head">
                    <div class="dash-list-title">
                        <span class="dash-list-title-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m14.7 6.3 3 3"></path>
                                <path d="M5 19 19 5"></path>
                                <path d="m3 21 3-3"></path>
                                <path d="m15 9 3 3"></path>
                            </svg>
                        </span>
                        <h2>Recent Repairs</h2>
                    </div>
                    <a href="/repairs" class="dash-list-link">View all</a>
                </div>
                @if($recentRepairs->isEmpty())
                    <div class="dash-empty">No repairs yet.</div>
                @else
                    <div class="dash-table-wrap">
                        <div class="dash-table-head">
                            <span>Repair Item</span>
                            <span>Opened</span>
                            <span>Status</span>
                        </div>
                        <div class="dash-table-body">
                            @foreach($recentRepairs as $rep)
                                @php
                                    $statusLabel = str_replace('_', ' ', ucfirst($rep->status));
                                    $statusTone = match ($rep->status) {
                                        'pending' => 'pending',
                                        'received', 'in_progress', 'in_repair' => 'progress',
                                        'ready' => 'ready',
                                        'delivered' => 'delivered',
                                        default => 'default',
                                    };
                                @endphp
                                <div class="dash-row">
                                    <div class="dash-row-main">
                                        <span class="dash-row-icon repair" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m14.7 6.3 3 3"></path>
                                                <path d="M5 19 19 5"></path>
                                                <path d="m3 21 3-3"></path>
                                                <path d="m15 9 3 3"></path>
                                            </svg>
                                        </span>
                                        <div class="dash-row-copy">
                                            <div class="dash-row-title">{{ \Illuminate\Support\Str::limit($rep->item_description, 36) }}</div>
                                            <div class="dash-row-meta">Ticket #{{ $rep->id }}</div>
                                        </div>
                                    </div>
                                    <div class="dash-row-mid">{{ $rep->created_at->format('d M') }}</div>
                                    <div class="dash-row-end">
                                        <span class="dash-tag {{ $statusTone }}">{{ $statusLabel }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="dash-block dash-table-card overflow-hidden dash-top-customers dash-recent-card">
                <div class="dash-list-head">
                    <div class="dash-list-title">
                        <span class="dash-list-title-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </span>
                        <h2>Top Customers · 30 Days</h2>
                    </div>
                    <a href="/customers" class="dash-list-link">View all</a>
                </div>
                @if($topCustomers->isEmpty())
                    <div class="dash-empty">No billed customers in the last 30 days.</div>
                @else
                    <div class="dash-table-wrap">
                        <div class="dash-table-head">
                            <span>Customer</span>
                            <span>Invoices</span>
                            <span>Spend</span>
                        </div>
                        <div class="dash-table-body">
                            @foreach($topCustomers as $customer)
                                @php
                                    $fullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                                    $displayName = $fullName !== '' ? $fullName : ('Customer #' . $customer->id);
                                    $invoiceCount = (int) $customer->invoice_count;
                                @endphp
                                <a href="/customers/{{ $customer->id }}" class="dash-row">
                                    <div class="dash-row-main">
                                        <span class="dash-row-icon customer" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                                <circle cx="12" cy="7" r="4"></circle>
                                            </svg>
                                            <span class="dash-top-rank">#{{ $loop->iteration }}</span>
                                        </span>
                                        <div class="dash-row-copy">
                                            <div class="dash-row-title">{{ $displayName }}</div>
                                            <div class="dash-row-meta">{{ $customer->mobile ?: 'No mobile' }}</div>
                                        </div>
                                    </div>
                                    <div class="dash-row-mid">{{ $invoiceCount }} {{ \Illuminate\Support\Str::plural('invoice', $invoiceCount) }}</div>
                                    <div class="dash-row-end">
                                        <span class="dash-amount">₹{{ number_format((float) $customer->total_spend, 2) }}</span>
                                        <svg class="dash-row-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>

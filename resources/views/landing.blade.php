<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JewelFlows - Jewellery Business Management Platform</title>
    <meta name="description" content="JewelFlows helps jewellery businesses run POS, stock, GST billing, karigar job work, mobile sales, reports, and gold loan operations from one cloud platform.">
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800,900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --ink: #1b1412;
            --ink-2: #44332f;
            --muted: #75655f;
            --paper: #fbf6ea;
            --paper-2: #efe3cf;
            --panel: #ffffff;
            --panel-2: #fffaf0;
            --line: #e1d2b8;
            --line-dark: #51372f;
            --dark: #17110f;
            --dark-2: #2a1714;
            --teal: #8a1538;
            --teal-2: #66102a;
            --teal-soft: #fff0f4;
            --gold: #c48a26;
            --gold-2: #8e5b12;
            --gold-soft: #fff1c6;
            --rose: #a52346;
            --rose-soft: #fff0f5;
            --blue: #5d3a9b;
            --blue-soft: #f4efff;
            --pearl: #fffaf0;
            --ruby: #8a1538;
            --ruby-soft: #fff0f4;
            --amethyst: #5d3a9b;
            --amethyst-soft: #f4efff;
            --saffron: #d49820;
            --saffron-soft: #fff1c6;
            --platinum: #ede7dc;
            --radius: 14px;
            --shadow-sm: 0 10px 24px rgba(66, 42, 25, .10);
            --shadow-lg: 0 26px 70px rgba(66, 42, 25, .24);
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            color: var(--ink);
            background: var(--paper);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        textarea {
            font: inherit;
        }

        .container {
            width: min(1180px, calc(100% - 40px));
            margin: 0 auto;
        }

        .nav {
            position: sticky;
            top: 0;
            z-index: 80;
            border-bottom: 1px solid rgba(225, 210, 184, .92);
            background: rgba(251, 246, 234, .9);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .nav-inner {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--ink);
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 0;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 10px;
            background: #17110f;
            color: #f5cf73;
            box-shadow: 0 12px 28px rgba(66, 42, 25, .24);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 26px;
            list-style: none;
        }

        .nav-links a {
            color: var(--ink-2);
            font-size: 14px;
            font-weight: 800;
            transition: color .18s ease;
        }

        .nav-links a:hover {
            color: var(--teal);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .login-link {
            display: inline-flex;
            align-items: center;
            min-height: 42px;
            padding: 0 12px;
            color: var(--ink-2);
            font-size: 14px;
            font-weight: 900;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 46px;
            padding: 0 19px;
            border: 1px solid transparent;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 900;
            white-space: nowrap;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease, color .18s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: var(--ruby);
            color: #ffffff;
            box-shadow: 0 16px 30px rgba(138, 21, 56, .24);
        }

        .btn-primary:hover {
            background: #68102c;
            box-shadow: 0 20px 38px rgba(138, 21, 56, .32);
        }

        .btn-secondary {
            border-color: var(--line);
            background: rgba(255, 255, 255, .84);
            color: var(--ink);
        }

        .btn-secondary:hover {
            border-color: var(--teal);
            color: var(--teal-2);
            box-shadow: var(--shadow-sm);
        }

        .hero {
            position: relative;
            overflow: hidden;
            color: var(--ink);
            background: #faf3e7;
            isolation: isolate;
        }

        .hero > .container {
            width: 100%;
            max-width: none;
            padding-inline: clamp(24px, 3vw, 40px);
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -2;
            opacity: .55;
            background-image:
                linear-gradient(rgba(196,138,38,.12) 1px, transparent 1px),
                linear-gradient(90deg, rgba(165,35,70,.06) 1px, transparent 1px);
            background-size: 74px 74px;
            transform: translateY(-18px);
        }

        .hero-inner {
            min-height: calc(100vh - 70px);
            padding: 48px 0 34px;
        }

        .hero-shell {
            position: relative;
            min-height: min(84vh, 820px);
            color: var(--ink);
            overflow: visible;
        }

        .hero-shell::before {
            content: none;
        }

        .hero-ghost {
            position: absolute;
            top: 12px;
            left: 50%;
            z-index: 1;
            transform: translateX(-50%);
            color: rgba(22, 17, 15, .1);
            font-size: clamp(128px, 20vw, 300px);
            line-height: .76;
            font-weight: 950;
            letter-spacing: -.1em;
            white-space: nowrap;
            pointer-events: none;
            user-select: none;
            text-shadow: none;
        }

        .hero-stage {
            position: absolute;
            inset: 0;
            z-index: 3;
            perspective: 1800px;
            pointer-events: none;
        }

        .hero-device-wrap {
            position: absolute;
            top: 14%;
            left: 50%;
            width: min(66%, 720px);
            padding: 0;
            transform: translateX(-50%);
            pointer-events: auto;
        }

        .device-panel {
            position: relative;
            transform-style: preserve-3d;
            will-change: transform;
            transition: transform .34s ease, filter .34s ease;
        }

        .device-panel::before {
            content: '';
            position: absolute;
            left: 18%;
            right: 18%;
            bottom: -18px;
            height: 34px;
            border-radius: 999px;
            background: rgba(80, 57, 34, .18);
            filter: blur(24px);
            z-index: -2;
        }

        .device-frame {
            position: relative;
            overflow: visible;
            background: transparent;
        }

        .device-frame picture {
            display: block;
        }

        .device-shot {
            display: block;
            width: 100%;
            height: auto;
            position: relative;
            z-index: 2;
        }

        .laptop-device {
            width: 100%;
            margin: 0 auto;
            transform: translateY(0);
            animation: floatStage 8s ease-in-out infinite;
            z-index: 1;
        }

        .laptop-device .device-frame::before,
        .laptop-device .device-frame::after {
            content: none;
        }

        .laptop-device:hover {
            transform: translateY(-12px) scale(1.015);
            filter: saturate(1.04);
        }

        .laptop-device .device-shot {
            filter: drop-shadow(0 34px 68px rgba(42, 27, 16, .18));
        }

        .hero-bottom {
            position: absolute;
            right: 28px;
            bottom: 28px;
            left: 28px;
            z-index: 5;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
        }

        .hero-copy-dock {
            max-width: 430px;
            padding-right: 16px;
        }

        .hero-meta-dock {
            width: min(100%, 318px);
            padding: 16px 0 0 22px;
            border-left: 1px solid rgba(22, 17, 15, .12);
        }

        .hero-brand-lockup {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .hero-brand-mark {
            width: 54px;
            height: 54px;
            flex: 0 0 auto;
            border-radius: 15px;
            border-color: rgba(255,255,255,.08);
            background: rgba(255,255,255,.06);
            color: #f3c86e;
            box-shadow: none;
        }

        .hero-brand-text {
            min-width: 0;
        }

        .hero-brand-name {
            color: var(--ink);
            font-size: 18px;
            line-height: .94;
            font-weight: 950;
            letter-spacing: -.01em;
        }

        .hero-brand-note {
            margin-top: 6px;
            color: rgba(156, 102, 16, .78);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .hero h1 {
            margin-top: 18px;
            color: var(--ink);
            font-size: clamp(28px, 3.1vw, 40px);
            line-height: 1.02;
            letter-spacing: -.03em;
            font-weight: 900;
        }

        .hero-sub {
            margin-top: 12px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.65;
            font-weight: 650;
        }

        .hero-panel-label {
            color: rgba(156, 102, 16, .82);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .hero-side-list {
            display: grid;
            gap: 12px;
            margin-top: 14px;
            list-style: none;
        }

        .hero-side-list li {
            padding-top: 12px;
            border-top: 1px solid rgba(22, 17, 15, .08);
        }

        .hero-side-list strong {
            display: block;
            color: var(--ink);
            font-size: 14px;
            font-weight: 900;
        }

        .hero-side-list span {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
            font-weight: 700;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .hero .btn-secondary {
            border-color: rgba(22,17,15,.12);
            background: rgba(255,255,255,.72);
            color: var(--ink);
        }

        .hero .btn-secondary:hover {
            border-color: rgba(156, 102, 16, .34);
            background: #ffffff;
            color: var(--ink);
            box-shadow: var(--shadow-sm);
        }

        .module-showcase {
            position: relative;
            overflow: hidden;
            padding: 54px 0 58px;
            border-top: 1px solid rgba(196,138,38,.16);
            border-bottom: 1px solid rgba(196,138,38,.14);
            background: #f5e8d2;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.52), 0 22px 54px rgba(128, 76, 26, .10);
        }

        .module-showcase::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: .48;
            background-image:
                linear-gradient(rgba(196,138,38,.10) 1px, transparent 1px),
                linear-gradient(90deg, rgba(165,35,70,.05) 1px, transparent 1px);
            background-size: 62px 62px;
            pointer-events: none;
        }

        .module-showcase-shell {
            position: relative;
            z-index: 2;
            margin-bottom: 22px;
        }

        .module-showcase-copy {
            max-width: 560px;
        }

        .module-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #f3c86e;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .module-label svg {
            color: #f0be5d;
        }

        .module-showcase-copy h2 {
            margin-top: 14px;
            color: var(--ink);
            font-size: clamp(30px, 4.4vw, 44px);
            line-height: 1.01;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .module-showcase-copy p {
            margin-top: 14px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            font-weight: 700;
        }

        .module-marquee {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .module-marquee::before,
        .module-marquee::after {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            width: 88px;
            z-index: 4;
            pointer-events: none;
        }

        .module-marquee::before {
            left: 0;
            background: linear-gradient(90deg, #f5e8d2 22%, rgba(245,232,210,0));
        }

        .module-marquee::after {
            right: 0;
            background: linear-gradient(270deg, #f5e8d2 22%, rgba(245,232,210,0));
        }

        .module-marquee-row {
            position: relative;
            overflow: hidden;
            padding-block: 8px;
        }

        .module-marquee-track {
            display: flex;
            width: max-content;
            align-items: stretch;
            animation: moduleMarquee 34s linear infinite;
            will-change: transform;
        }

        .module-marquee-row.reverse .module-marquee-track {
            animation-duration: 40s;
            animation-direction: reverse;
        }

        .module-marquee-row:hover .module-marquee-track {
            animation-play-state: paused;
        }

        .module-marquee-group {
            display: flex;
            align-items: stretch;
            gap: 14px;
            flex: none;
            padding-inline-end: 14px;
        }

        .module-chip {
            --chip-bg: #f8eedb;
            --chip-border: #d9b879;
            --chip-accent: #9b6814;
            --chip-copy: #5f4830;
            --chip-orb: rgba(214, 177, 115, .26);
            position: relative;
            flex: 0 0 280px;
            min-height: 160px;
            overflow: hidden;
            padding: 18px 18px 16px;
            border: 1px solid var(--chip-border);
            border-radius: 22px;
            background: var(--chip-bg);
            color: #221714;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.54);
            transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease;
        }

        .module-chip::before {
            content: '';
            position: absolute;
            right: -10px;
            bottom: -30px;
            width: 110px;
            height: 110px;
            border-radius: 999px;
            background: var(--chip-orb);
            pointer-events: none;
        }

        .module-chip::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.46);
            pointer-events: none;
        }

        .module-chip:hover {
            transform: translateY(-7px) scale(1.02);
            box-shadow: 0 10px 24px rgba(196, 138, 38, .08), inset 0 1px 0 rgba(255,255,255,.62);
        }

        .module-chip-kicker {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--chip-accent);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .module-chip-kicker::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: currentColor;
            box-shadow: 0 0 0 5px rgba(255,255,255,.22);
        }

        .module-chip h3 {
            position: relative;
            z-index: 2;
            margin-top: 18px;
            max-width: 182px;
            font-size: 20px;
            line-height: 1.08;
            font-weight: 900;
            letter-spacing: -.02em;
        }

        .module-chip p {
            position: relative;
            z-index: 2;
            margin-top: 10px;
            color: var(--chip-copy);
            font-size: 13px;
            line-height: 1.55;
            font-weight: 700;
        }

        .module-chip--gold {
            --chip-bg: #f7edd7;
            --chip-border: #d7b16a;
            --chip-accent: #9c6610;
            --chip-copy: #5f472d;
            --chip-orb: rgba(215, 177, 106, .28);
        }

        .module-chip--ruby {
            --chip-bg: #f6dfe5;
            --chip-border: #ce8397;
            --chip-accent: #8a1538;
            --chip-copy: #612d3c;
            --chip-orb: rgba(138, 21, 56, .18);
        }

        .module-chip--amethyst {
            --chip-bg: #ece4f7;
            --chip-border: #a98bcd;
            --chip-accent: #5d3a9b;
            --chip-copy: #4c376f;
            --chip-orb: rgba(93, 58, 155, .18);
        }

        .module-chip--saffron {
            --chip-bg: #f9e7cc;
            --chip-border: #daa356;
            --chip-accent: #a26010;
            --chip-copy: #61452a;
            --chip-orb: rgba(212, 152, 32, .18);
        }

        .module-chip--pearl {
            --chip-bg: #efe6da;
            --chip-border: #bca88e;
            --chip-accent: #6a5445;
            --chip-copy: #5e4a3d;
            --chip-orb: rgba(188, 168, 142, .24);
        }

        .module-chip--onyx {
            --chip-bg: #241917;
            --chip-border: rgba(243, 200, 110, .28);
            --chip-accent: #f3c86e;
            --chip-copy: rgba(255,255,255,.72);
            --chip-orb: rgba(243, 200, 110, .12);
            color: #ffffff;
        }

        section {
            padding: 100px 0;
        }

        .section-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 34px;
            margin-bottom: 34px;
        }

        .eyebrow {
            display: inline-flex;
            margin-bottom: 12px;
            color: var(--teal-2);
            font-size: 12px;
            font-weight: 900;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .section-title {
            max-width: 760px;
            color: var(--ink);
            font-size: clamp(32px, 5vw, 52px);
            line-height: 1.05;
            letter-spacing: 0;
            font-weight: 900;
        }

        .section-copy {
            max-width: 540px;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.7;
            font-weight: 650;
        }

        .workflow-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .workflow-card,
        .feature-card,
        .step-card {
            position: relative;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: rgba(255,255,255,.72);
            box-shadow: 0 12px 28px rgba(16, 24, 40, .06);
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background .22s ease;
            overflow: hidden;
        }

        .workflow-card::before,
        .feature-card::before {
            content: '';
            position: absolute;
            inset: 0 0 auto;
            height: 3px;
            background: var(--teal);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .26s ease;
        }

        .workflow-card:hover,
        .feature-card:hover,
        .step-card:hover {
            transform: translateY(-6px);
            border-color: rgba(138, 21, 56, .34);
            background: #ffffff;
            box-shadow: 0 24px 58px rgba(66, 42, 25, .14);
        }

        .workflow-card:hover::before,
        .feature-card:hover::before {
            transform: scaleX(1);
        }

        .workflow-card {
            min-height: 248px;
            padding: 24px;
        }

        .card-icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            margin-bottom: 18px;
            border-radius: 12px;
            background: var(--teal-soft);
            color: var(--teal-2);
        }

        .card-icon.gold {
            background: var(--gold-soft);
            color: var(--gold-2);
        }

        .card-icon.blue {
            background: var(--blue-soft);
            color: var(--blue);
        }

        .workflow-title,
        .feature-title,
        .step-title {
            color: var(--ink);
            font-size: 19px;
            line-height: 1.32;
            font-weight: 900;
        }

        .workflow-copy,
        .feature-copy,
        .step-copy {
            margin-top: 10px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
            font-weight: 650;
        }

        .check-list {
            display: grid;
            gap: 10px;
            margin-top: 20px;
            list-style: none;
        }

        .check-list li {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            color: var(--ink-2);
            font-size: 13px;
            line-height: 1.45;
            font-weight: 850;
        }

        .check {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            display: grid;
            place-items: center;
            border-radius: 999px;
            background: var(--teal-soft);
            color: var(--teal-2);
        }

        .feature-section {
            background: #ffffff;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }

        .feature-card {
            min-height: 210px;
            padding: 20px;
            box-shadow: none;
        }

        .feature-kicker {
            display: inline-flex;
            margin-bottom: 10px;
            color: var(--teal-2);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .split-section {
            position: relative;
            background: var(--paper);
        }

        .split-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 430px;
            gap: 44px;
            align-items: center;
        }

        .ops-list {
            display: grid;
            gap: 12px;
            margin-top: 26px;
        }

        .ops-item {
            display: grid;
            grid-template-columns: 42px 1fr;
            gap: 14px;
            align-items: start;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255,255,255,.74);
            transition: transform .2s ease, background .2s ease, box-shadow .2s ease;
        }

        .ops-item:hover {
            transform: translateX(4px);
            background: #ffffff;
            box-shadow: var(--shadow-sm);
        }

        .ops-number {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--ruby);
            color: #ffffff;
            font-size: 13px;
            font-weight: 900;
        }

        .ops-title {
            color: var(--ink);
            font-size: 15px;
            font-weight: 900;
        }

        .ops-copy {
            margin-top: 5px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            font-weight: 650;
        }

        .phone-preview {
            position: relative;
            width: min(100%, 390px);
            margin-left: auto;
            perspective: 1800px;
            padding: 14px 28px 46px 0;
        }

        .phone-preview::after {
            content: '';
            position: absolute;
            left: 16%;
            right: 12%;
            bottom: -18px;
            height: 42px;
            border-radius: 999px;
            background: rgba(111, 71, 34, .18);
            filter: blur(24px);
            pointer-events: none;
            z-index: 0;
        }

        .phone-device {
            width: min(100%, 340px);
            margin-left: auto;
            transform: rotateX(10deg) rotateY(-16deg) rotateZ(-2deg);
            z-index: 1;
        }

        .phone-device .device-frame::before,
        .phone-device .device-frame::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 40px;
            pointer-events: none;
        }

        .phone-device .device-frame::before {
            background: rgba(201, 183, 231, .78);
            border: 1px solid rgba(145, 118, 196, .16);
            transform: translate3d(14px, 14px, -1px);
            z-index: 1;
        }

        .phone-device .device-frame::after {
            background: rgba(244, 236, 253, .94);
            border: 1px solid rgba(145, 118, 196, .12);
            transform: translate3d(28px, 28px, -2px);
            z-index: 0;
        }

        .phone-device:hover {
            transform: rotateX(6deg) rotateY(-9deg) rotateZ(-1deg) translateY(-12px);
        }

        .phone-device .device-shot {
            aspect-ratio: 2 / 3;
            object-fit: cover;
            border: 1px solid rgba(65, 39, 24, .12);
            border-radius: 40px;
            box-shadow: 0 30px 68px rgba(111, 71, 34, .18);
        }

        .steps {
            background: #ffffff;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .step-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .step-card {
            padding: 24px;
            box-shadow: none;
        }

        .step-num {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            margin-bottom: 18px;
            border-radius: 999px;
            background: var(--gold-soft);
            color: var(--gold-2);
            font-size: 13px;
            font-weight: 900;
        }

        .cta {
            padding: 78px 0;
            color: var(--ink);
            background: #f7efe3;
            position: relative;
            overflow: hidden;
            border-top: 1px solid var(--line);
        }

        .cta::before {
            content: '';
            position: absolute;
            inset: 18px;
            border: 1px solid rgba(196,138,38,.16);
            border-radius: 28px;
            pointer-events: none;
        }

        .cta-inner {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 34px;
        }

        .cta h2 {
            max-width: 760px;
            color: var(--ink);
            font-size: clamp(34px, 5vw, 56px);
            line-height: 1.04;
            letter-spacing: 0;
            font-weight: 900;
        }

        .cta p {
            max-width: 650px;
            margin-top: 14px;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.65;
            font-weight: 650;
        }

        .cta-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 12px;
        }

        .cta .btn-secondary {
            border-color: var(--line);
            background: rgba(255,255,255,.82);
            color: var(--ink);
        }

        .cta .btn-secondary:hover {
            border-color: var(--ruby);
            background: #ffffff;
            color: var(--teal-2);
        }

        .footer {
            padding: 30px 0;
            border-top: 1px solid var(--line);
            background: var(--panel);
        }

        .footer-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .footer-links {
            display: flex;
            gap: 18px;
            list-style: none;
        }

        .footer a:hover {
            color: var(--teal-2);
        }

        [data-reveal] {
            opacity: 0;
            transform: translateY(22px);
            transition: opacity .7s ease, transform .7s ease;
        }

        [data-reveal].is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        svg {
            display: block;
        }

        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(245, 207, 115, .38);
            }
            50% {
                box-shadow: 0 0 0 8px rgba(245, 207, 115, 0);
            }
        }

        @keyframes floatStage {
            0%, 100% {
                transform: translate3d(0, 0, 0) rotate(-.3deg);
            }
            50% {
                transform: translate3d(0, -12px, 0) rotate(.3deg);
            }
        }

        @keyframes floatCardOne {
            0%, 100% {
                transform: translate3d(0, 0, 0);
            }
            50% {
                transform: translate3d(8px, -12px, 0);
            }
        }

        @keyframes floatCardTwo {
            0%, 100% {
                transform: translate3d(0, 0, 0);
            }
            50% {
                transform: translate3d(-10px, 10px, 0);
            }
        }

        @keyframes moduleMarquee {
            from {
                transform: translate3d(0, 0, 0);
            }
            to {
                transform: translate3d(-50%, 0, 0);
            }
        }

        @media (max-width: 1120px) {
            .hero > .container {
                padding-inline: 24px;
            }

            .hero-inner {
                min-height: auto;
            }

            .hero-shell {
                min-height: 780px;
            }

            .hero-ghost {
                top: 40px;
                font-size: clamp(96px, 17vw, 200px);
            }

            .hero-device-wrap {
                top: 22%;
                width: min(68%, 560px);
            }

            .hero-bottom {
                right: 22px;
                bottom: 22px;
                left: 22px;
                gap: 14px;
            }

            .hero-copy-dock {
                max-width: 380px;
            }

            .hero-meta-dock {
                width: min(100%, 292px);
                padding-left: 18px;
            }

            .workflow-grid,
            .step-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .feature-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .split-grid {
                grid-template-columns: 1fr;
            }

            .phone-preview {
                margin: 0;
            }

            .cta-inner {
                align-items: flex-start;
                flex-direction: column;
            }

            .cta-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 780px) {
            .container {
                width: min(100% - 28px, 1180px);
            }

            .hero > .container {
                width: 100%;
                padding-inline: 14px;
            }

            .nav-inner {
                min-height: 64px;
            }

            .nav-links {
                display: none;
            }

            .login-link {
                display: none;
            }

            .brand {
                font-size: 18px;
            }

            .hero-inner {
                padding: 32px 0 26px;
            }

            .hero-shell {
                min-height: auto;
            }

            .hero-ghost {
                top: 42px;
                width: min(92vw, 360px);
                font-size: clamp(62px, 17vw, 82px);
                text-align: center;
                letter-spacing: -.08em;
                white-space: nowrap;
            }

            .hero-stage {
                position: relative;
                min-height: auto;
                padding-top: 60px;
            }

            .hero-device-wrap {
                position: relative;
                top: auto;
                left: auto;
                width: min(84vw, 320px);
                margin: 0 auto;
                transform: none;
            }

            .laptop-device {
                width: 100%;
                transform: rotateX(8deg) rotateY(-6deg) rotateZ(-1deg);
            }

            .hero-bottom {
                position: static;
                margin-top: 18px;
                flex-direction: column;
                align-items: stretch;
                gap: 18px;
            }

            .hero-copy-dock,
            .hero-meta-dock {
                width: 100%;
                max-width: none;
                padding: 0;
            }

            .hero-brand-lockup {
                gap: 12px;
            }

            .hero-brand-mark {
                width: 46px;
                height: 46px;
                border-radius: 14px;
            }

            .hero-brand-name {
                font-size: 18px;
            }

            .hero-brand-note {
                font-size: 10px;
                letter-spacing: .14em;
            }

            .hero-meta-dock {
                border-left: 0;
                border-top: 1px solid rgba(22, 17, 15, .08);
                padding-top: 16px;
            }

            .hero h1 {
                font-size: 26px;
            }

            .hero-sub {
                font-size: 14px;
            }

            .hero-actions,
            .cta-actions {
                align-items: stretch;
                flex-direction: column;
            }

            .hero-actions .btn,
            .cta-actions .btn {
                width: 100%;
            }

            .workflow-grid,
            .feature-grid,
            .step-grid {
                grid-template-columns: 1fr;
            }

            .module-showcase {
                padding: 34px 0 38px;
            }

            .module-showcase-shell {
                margin-bottom: 18px;
            }

            .module-showcase-copy h2 {
                font-size: 28px;
            }

            .module-showcase-copy p {
                font-size: 13px;
            }

            .module-marquee {
                gap: 12px;
            }

            .module-marquee::before,
            .module-marquee::after {
                width: 34px;
            }

            .module-marquee-track {
                animation-duration: 30s;
            }

            .module-marquee-row.reverse .module-marquee-track {
                animation-duration: 35s;
            }

            .module-marquee-group {
                gap: 12px;
                padding-inline-end: 12px;
            }

            .module-chip {
                flex-basis: min(78vw, 258px);
                min-height: 146px;
                padding: 16px;
            }

            .module-chip h3 {
                margin-top: 14px;
                font-size: 18px;
            }

            section {
                padding: 72px 0;
            }

            .section-head {
                align-items: flex-start;
                flex-direction: column;
                gap: 12px;
            }

            .workflow-card {
                min-height: auto;
            }

            .module-marquee-row:hover .module-marquee-track {
                animation-play-state: running;
            }

            .phone-preview {
                width: 100%;
                max-width: 318px;
                margin: 0 auto;
                padding: 10px 12px 30px;
            }

            .laptop-device {
                width: min(100%, 100%);
            }

            .phone-device {
                width: min(100%, 288px);
                margin: 0 auto;
                transform: rotateX(7deg) rotateY(-9deg) rotateZ(-1deg);
            }

            .cta {
                padding: 62px 0;
            }

            .cta::before {
                inset: 12px;
                border-radius: 18px;
            }

            .footer-inner {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 640px) {
            .nav-inner {
                gap: 14px;
            }

            .hero-inner {
                padding: 28px 0 22px;
            }

            .hero-ghost {
                top: 28px;
                width: min(92vw, 340px);
                font-size: clamp(56px, 16vw, 72px);
            }

            .hero-stage {
                padding-top: 44px;
            }

            .hero-device-wrap {
                width: min(82vw, 300px);
            }

            .hero-copy-dock {
                padding-right: 0;
            }

            .hero h1 {
                margin-top: 16px;
                font-size: clamp(22px, 7vw, 30px);
                line-height: 1.05;
            }

            .hero-sub {
                max-width: 32ch;
                font-size: 13px;
                line-height: 1.58;
            }

            .hero-side-list {
                gap: 10px;
                margin-top: 12px;
            }

            .hero-side-list li {
                padding-top: 10px;
            }

            .hero-actions {
                margin-top: 16px;
            }

            section {
                padding: 60px 0;
            }

            .split-grid {
                gap: 28px;
            }

            .section-title {
                font-size: clamp(28px, 8vw, 36px);
            }

            .section-copy {
                font-size: 14px;
            }

            .ops-list {
                margin-top: 22px;
                gap: 10px;
            }

            .ops-item {
                grid-template-columns: 38px 1fr;
                gap: 12px;
                padding: 14px;
            }

            .ops-number {
                width: 38px;
                height: 38px;
                border-radius: 11px;
                font-size: 12px;
            }

            .phone-preview {
                max-width: 300px;
                padding: 8px 10px 26px;
            }

            .phone-preview::after {
                left: 18%;
                right: 18%;
                bottom: -8px;
                height: 28px;
                filter: blur(18px);
            }

            .phone-device {
                width: min(100%, 280px);
                transform: rotateX(6deg) rotateY(-8deg) rotateZ(-1deg);
            }

            .phone-device .device-frame::before {
                border-radius: 34px;
                transform: translate3d(10px, 10px, -1px);
            }

            .phone-device .device-frame::after {
                border-radius: 34px;
                transform: translate3d(20px, 20px, -2px);
            }

            .phone-device .device-shot {
                border-radius: 34px;
                box-shadow: 0 22px 48px rgba(111, 71, 34, .16);
            }
        }

        @media (max-width: 480px) {
            .container {
                width: min(100% - 20px, 1180px);
            }

            .hero > .container {
                padding-inline: 10px;
            }

            .nav-inner {
                min-height: 60px;
            }

            .brand {
                gap: 8px;
                font-size: 17px;
            }

            .brand-mark {
                width: 32px;
                height: 32px;
                border-radius: 9px;
            }

            .nav .btn {
                min-height: 40px;
                padding-inline: 14px;
                font-size: 13px;
            }

            .hero-inner {
                padding: 24px 0 18px;
            }

            .hero-ghost {
                top: 22px;
                width: min(94vw, 332px);
                font-size: clamp(52px, 15vw, 64px);
                letter-spacing: -.07em;
            }

            .hero-stage {
                padding-top: 38px;
            }

            .hero-device-wrap {
                width: min(80vw, 272px);
            }

            .hero-bottom {
                margin-top: 12px;
                gap: 16px;
            }

            .hero-brand-lockup {
                gap: 10px;
            }

            .hero-brand-mark {
                width: 42px;
                height: 42px;
                border-radius: 13px;
            }

            .hero-brand-name {
                font-size: 17px;
            }

            .hero-brand-note {
                font-size: 10px;
                letter-spacing: .12em;
            }

            .hero h1 {
                font-size: clamp(20px, 8.3vw, 26px);
            }

            .hero-sub {
                margin-top: 10px;
                font-size: 13px;
            }

            .hero-panel-label {
                font-size: 10px;
                letter-spacing: .12em;
            }

            .hero-side-list strong {
                font-size: 13px;
            }

            .hero-side-list span {
                font-size: 11px;
            }

            .hero-actions {
                gap: 8px;
            }

            section {
                padding: 56px 0;
            }

            .module-showcase {
                padding: 28px 0 32px;
            }

            .module-chip {
                flex-basis: 82vw;
                min-height: 138px;
                padding: 14px;
            }

            .split-grid {
                gap: 24px;
            }

            .phone-preview {
                max-width: 276px;
                padding: 6px 8px 20px;
            }

            .phone-preview::after {
                bottom: -6px;
                height: 24px;
                filter: blur(14px);
            }

            .phone-device {
                width: min(100%, 258px);
                transform: rotateX(5deg) rotateY(-7deg) rotateZ(-.75deg);
            }

            .phone-device .device-frame::before {
                border-radius: 30px;
                transform: translate3d(8px, 8px, -1px);
            }

            .phone-device .device-frame::after {
                border-radius: 30px;
                transform: translate3d(16px, 16px, -2px);
            }

            .phone-device .device-shot {
                border-radius: 30px;
            }

            .cta {
                padding: 52px 0;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }

            .module-marquee::before,
            .module-marquee::after {
                display: none;
            }

            .module-marquee-row {
                overflow-x: auto;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }

            .module-marquee-row::-webkit-scrollbar {
                display: none;
            }

            .module-marquee-track {
                animation: none;
            }
        }
    </style>
</head>
<body>
    <nav class="nav" aria-label="Primary navigation">
        <div class="container">
            <div class="nav-inner">
                <a href="/" class="brand" aria-label="JewelFlows home">
                    <span class="brand-mark" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M6 9.5 12 3l6 6.5L12 21 6 9.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                            <path d="M6 9.5h12M9.2 9.5 12 21l2.8-11.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        </svg>
                    </span>
                    JewelFlows
                </a>

                <div class="nav-actions">
                    <a href="{{ route('login') }}" class="login-link">Log in</a>
                    <a href="mailto:{{ config('app.support_email') }}?subject=JewelFlows%20Enquiry" class="btn btn-primary">Talk to us</a>
                </div>
            </div>
        </div>
    </nav>

    <main>
        <section class="hero">
            <div class="container">
                <div class="hero-inner">
                    <div class="hero-shell" data-reveal>
                        <div class="hero-ghost" aria-hidden="true">JewelFlows</div>

                        <div class="hero-stage">
                            <div class="hero-device-wrap" aria-label="JewelFlows desktop dashboard preview">
                                <div class="laptop-device device-panel">
                                    <div class="device-frame">
                                        <picture>
                                            <source
                                                type="image/avif"
                                                srcset="/images/laptopview-960.avif 960w, /images/laptopview-1280.avif 1280w"
                                                sizes="(max-width: 480px) 272px, (max-width: 780px) 320px, (max-width: 1120px) 560px, 720px">
                                            <source
                                                type="image/webp"
                                                srcset="/images/laptopview-960.webp 960w, /images/laptopview-1280.webp 1280w"
                                                sizes="(max-width: 480px) 272px, (max-width: 780px) 320px, (max-width: 1120px) 560px, 720px">
                                            <img
                                                src="/images/laptopview.png"
                                                alt="JewelFlows desktop dashboard view"
                                                class="device-shot"
                                                width="1536"
                                                height="1024"
                                                decoding="async"
                                                fetchpriority="high">
                                        </picture>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hero-bottom">
                            <div class="hero-copy-dock">
                                <div class="hero-brand-lockup" aria-label="JewelFlows brand">
                                    <span class="hero-brand-mark brand-mark" aria-hidden="true">
                                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                                            <path d="M6 9.5 12 3l6 6.5L12 21 6 9.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                            <path d="M6 9.5h12M9.2 9.5 12 21l2.8-11.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                        </svg>
                                    </span>
                                    <div class="hero-brand-text">
                                        <div class="hero-brand-name">JewelFlows</div>
                                        <div class="hero-brand-note">Jewellery Business Operating System</div>
                                    </div>
                                </div>
                                <h1>Jewellery billing, stock, bullion, and karigar work in one system.</h1>
                                <p class="hero-sub">Built for retail shops, karigar workflows, and gold loan teams.</p>
                            </div>

                            <div class="hero-meta-dock">
                                <div class="hero-panel-label">Inside JewelFlows</div>
                                <ul class="hero-side-list">
                                    <li>
                                        <strong>Retail desk</strong>
                                        <span>POS billing, GST, and invoice flow.</span>
                                    </li>
                                    <li>
                                        <strong>Owner control</strong>
                                        <span>Daily rates, stock visibility, and reports.</span>
                                    </li>
                                    <li>
                                        <strong>Job work</strong>
                                        <span>Karigar challans, bullion, and returns.</span>
                                    </li>
                                </ul>
                                <div class="hero-actions">
                                    <a href="mailto:{{ config('app.support_email') }}?subject=JewelFlows%20Enquiry" class="btn btn-primary">Talk to us</a>
                                    <a href="{{ route('login') }}" class="btn btn-secondary">Log in</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @php
            $modules = [
                ['tone' => 'onyx', 'eyebrow' => 'Retail desk', 'title' => 'POS billing', 'copy' => 'Sales, GST, and invoices.'],
                ['tone' => 'gold', 'eyebrow' => 'Inventory', 'title' => 'Barcode stock', 'copy' => 'Purity, HUID, weight, and movement.'],
                ['tone' => 'ruby', 'eyebrow' => 'Owner control', 'title' => 'Daily rates', 'copy' => 'Owner-entered gold and silver rates.'],
                ['tone' => 'saffron', 'eyebrow' => 'Job work', 'title' => 'Karigar challans', 'copy' => 'Bullion issue, return, and settlement.'],
                ['tone' => 'pearl', 'eyebrow' => 'Bullion', 'title' => 'Vault lots', 'copy' => 'Lot-level bullion tracking.'],
                ['tone' => 'amethyst', 'eyebrow' => 'Finance', 'title' => 'Gold loans', 'copy' => 'Receipts, closures, and follow-up.'],
            ];
        @endphp

        <div class="module-showcase" aria-label="JewelFlows built-in workflows">
            <div class="container">
                <div class="module-showcase-shell" data-reveal>
                    <div class="module-showcase-copy">
                        <h2>Six daily workflows. One connected system.</h2>
                    </div>
                </div>

                <div class="module-marquee">
                    <div class="module-marquee-row">
                        <div class="module-marquee-track">
                            @for ($copy = 0; $copy < 2; $copy++)
                                <div class="module-marquee-group" @if ($copy === 1) aria-hidden="true" @endif>
                                    @foreach ($modules as $module)
                                        <article class="module-chip module-chip--{{ $module['tone'] }}">
                                            <span class="module-chip-kicker">{{ $module['eyebrow'] }}</span>
                                            <h3>{{ $module['title'] }}</h3>
                                            <p>{{ $module['copy'] }}</p>
                                        </article>
                                    @endforeach
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <section id="workflows">
            <div class="container">
                <div class="section-head" data-reveal>
                    <div>
                        <span class="eyebrow">Business fit</span>
                        <h2 class="section-title">Built for the three jewellery workflows teams actually run.</h2>
                    </div>
                </div>

                <div class="workflow-grid">
                    <article class="workflow-card" data-reveal>
                        <div class="card-icon gold" aria-hidden="true">
                            <svg width="21" height="21" viewBox="0 0 24 24" fill="none">
                                <path d="M4 7h16l-1.5 13h-13L4 7ZM7 7l1-3h8l1 3M9 11v5M15 11v5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h3 class="workflow-title">Retail jewellery shops</h3>
                        <p class="workflow-copy">Sell, bill, and manage stock from one desk without switching between billing, inventory, and report tools.</p>
                    </article>

                    <article class="workflow-card" data-reveal>
                        <div class="card-icon" aria-hidden="true">
                            <svg width="21" height="21" viewBox="0 0 24 24" fill="none">
                                <path d="M14.5 5.5 18 2l4 4-3.5 3.5M14.5 5.5l4 4M14.5 5.5 4 16v4h4L18.5 9.5M3 21h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h3 class="workflow-title">Manufacturing and karigar work</h3>
                        <p class="workflow-copy">Issue bullion, track returns, watch wastage, and keep challans and invoices tied to the same records.</p>
                    </article>

                    <article class="workflow-card" data-reveal>
                        <div class="card-icon blue" aria-hidden="true">
                            <svg width="21" height="21" viewBox="0 0 24 24" fill="none">
                                <path d="M5 11h14M7 7h10M6 15h12M8 19h8M4 3h16v18H4V3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h3 class="workflow-title">Gold loan operations</h3>
                        <p class="workflow-copy">Handle pledges, receipts, closures, and daily cash control without forcing loan work into generic ERP screens.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="split-section" id="mobile">
            <div class="container">
                <div class="split-grid">
                    <div data-reveal>
                        <span class="eyebrow">Mobile ready</span>
                        <h2 class="section-title">Use the same shop records on mobile.</h2>
                        <p class="section-copy" style="margin-top: 16px;">Check rates, search stock, and keep owner visibility without creating a second system for the app.</p>

                        <div class="ops-list">
                            <div class="ops-item">
                                <div class="ops-number">01</div>
                                <div>
                                    <div class="ops-title">Owner-entered daily rates</div>
                                    <div class="ops-copy">Billing stays tied to the shop's own saved rate.</div>
                                </div>
                            </div>
                            <div class="ops-item">
                                <div class="ops-number">02</div>
                                <div>
                                    <div class="ops-title">Fast item lookup</div>
                                    <div class="ops-copy">Barcode and item search stay quick on the floor.</div>
                                </div>
                            </div>
                            <div class="ops-item">
                                <div class="ops-number">03</div>
                                <div>
                                    <div class="ops-title">Same business records</div>
                                    <div class="ops-copy">Mobile actions update the same shop data as web.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="phone-preview" aria-label="Mobile app preview" data-reveal>
                        <div class="phone-device device-panel">
                            <div class="device-frame">
                                <picture>
                                    <source
                                        type="image/avif"
                                        srcset="/images/phoneview-480.avif 480w, /images/phoneview-768.avif 768w"
                                        sizes="(max-width: 480px) 258px, (max-width: 780px) 288px, 340px">
                                    <source
                                        type="image/webp"
                                        srcset="/images/phoneview-480.webp 480w, /images/phoneview-768.webp 768w"
                                        sizes="(max-width: 480px) 258px, (max-width: 780px) 288px, 340px">
                                    <img
                                        src="/images/phoneview.png"
                                        alt="JewelFlows mobile dashboard view"
                                        class="device-shot"
                                        width="1024"
                                        height="1536"
                                        loading="lazy"
                                        decoding="async">
                                </picture>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="cta">
            <div class="container">
                <div class="cta-inner">
                    <div data-reveal>
                        <h2>Want to see JewelFlows on your shop workflow?</h2>
                        <p>Mail the team for a closer look, or use the existing login if you already run JewelFlows.</p>
                    </div>
                    <div class="cta-actions" data-reveal>
                        <a href="mailto:{{ config('app.support_email') }}?subject=JewelFlows%20Enquiry" class="btn btn-primary">Talk to us</a>
                        <a href="{{ route('login') }}" class="btn btn-secondary">Log in</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer-inner">
                <div class="brand">
                    <span class="brand-mark" aria-hidden="true" style="width:28px;height:28px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                            <path d="M6 9.5 12 3l6 6.5L12 21 6 9.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    JewelFlows
                </div>
                <ul class="footer-links">
                    <li><a href="{{ route('login') }}">Login</a></li>
                    <li><a href="mailto:{{ config('app.support_email') }}">Support</a></li>
                </ul>
                <div>&copy; {{ date('Y') }} JewelFlows. All rights reserved.</div>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            const revealItems = document.querySelectorAll('[data-reveal]');

            if (!('IntersectionObserver' in window)) {
                revealItems.forEach((item) => item.classList.add('is-visible'));
                return;
            }

            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                });
            }, { threshold: 0.16 });

            revealItems.forEach((item, index) => {
                item.style.transitionDelay = `${Math.min(index * 45, 240)}ms`;
                observer.observe(item);
            });
        })();
    </script>
</body>
</html>

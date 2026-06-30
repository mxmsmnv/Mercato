<?php
namespace ProcessWire;

if (!function_exists(__NAMESPACE__ . '\\mrc_storefront_assets')) {
    function mrc_storefront_assets(bool $isVanilla): string {
        if ($isVanilla) {
            return '';
        }

        return <<<'HTML'
<style>
@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap');
:root {
    --mrc-ink: #33251f;
    --mrc-pine: #1b2e29;
    --mrc-paper: #f3f2f0;
    --mrc-ivory: #ece9e4;
    --mrc-cream: #fffaf2;
    --mrc-line: #d6cbbb;
    --mrc-muted: #746858;
    --mrc-gold: #a5917c;
    --mrc-rust: #7d3a31;
    --mrc-radius: 6px;
    --mrc-radius-sm: 6px;
    --mrc-pill: 6px;
    --mrc-shadow: 0 28px 80px rgba(27, 46, 41, 0.12);
    --mrc-shell: 1280px;
    --mrc-shell-pad: clamp(16px, 2.5vw, 32px);
}
.mrc-luxury-theme {
    background: var(--mrc-paper);
    color: var(--mrc-ink);
    font-family: Inter, Avenir, ui-sans-serif, system-ui, sans-serif;
    margin: 0;
}
.mrc-display {
    font-family: "Cormorant Garamond", Canela, Georgia, serif;
    letter-spacing: 0;
}
.mrc-small-caps,
.mrc-luxury-theme .mrc-kicker {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .24em;
    text-transform: uppercase;
}
.mrc-site-header {
    left: 0;
    position: sticky;
    right: 0;
    top: 0;
    z-index: 50;
}
.mrc-site-header-inner {
    align-items: center;
    box-sizing: border-box;
    display: flex;
    justify-content: space-between;
    margin-inline: auto;
    max-width: var(--mrc-shell);
    padding: 22px var(--mrc-shell-pad);
    width: 100%;
}
.mrc-site-brand {
    display: inline-flex;
}
.mrc-site-header.is-scrolled,
.mrc-site-header.is-open {
    background: rgba(243, 242, 240, .94);
    border-bottom: 1px solid rgba(165, 145, 124, .32);
}
.mrc-site-header.is-scrolled .mrc-site-header-inner,
.mrc-site-header.is-open .mrc-site-header-inner {
    padding-bottom: 16px;
    padding-top: 16px;
}
.mrc-site-brand {
    color: var(--mrc-ink);
    font-size: clamp(25px, 2.4vw, 38px);
    font-weight: 700;
    line-height: 1;
    text-decoration: none;
}
.mrc-site-nav {
    align-items: center;
    display: flex;
    gap: clamp(18px, 3vw, 42px);
}
.mrc-site-nav a,
.mrc-menu-toggle {
    color: var(--mrc-ink);
    text-decoration: none;
}
.mrc-site-nav a {
    position: relative;
}
.mrc-site-nav a:after {
    background: currentColor;
    bottom: -6px;
    content: "";
    height: 1px;
    left: 0;
    position: absolute;
    width: 100%;
}
.mrc-site-nav a:not([aria-current="page"]):after {
    display: none;
}
.mrc-menu-toggle {
    align-items: center;
    background: transparent;
    border: 0;
    cursor: pointer;
    display: none;
    gap: 10px;
    padding: 0;
}
.mrc-menu-lines {
    display: grid;
    gap: 4px;
    width: 24px;
}
.mrc-menu-lines span {
    background: currentColor;
    display: block;
    height: 1px;
}
.mrc-site-header.is-open .mrc-menu-lines span:first-child {
    transform: translateY(5px) rotate(45deg);
}
.mrc-site-header.is-open .mrc-menu-lines span:last-child {
    transform: translateY(-5px) rotate(-45deg);
}
.mrc-menu-panel {
    background: var(--mrc-pine);
    color: var(--mrc-ivory);
    inset: 0;
    opacity: 0;
    padding: 92px clamp(22px, 5vw, 72px) 40px;
    pointer-events: none;
    position: fixed;
    transform: translateY(-16px);
    z-index: 40;
}
.mrc-menu-panel.is-open {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
}
.mrc-menu-panel a {
    color: var(--mrc-ivory);
    text-decoration: none;
}
.mrc-menu-list {
    display: grid;
    gap: 18px;
    list-style: none;
    margin: 0;
    padding: 0;
}
.mrc-menu-list a {
    font-family: "Cormorant Garamond", Georgia, serif;
    font-size: clamp(42px, 9vw, 100px);
    font-weight: 600;
    line-height: .88;
}
.mrc-section {
    box-sizing: border-box;
    margin-inline: auto;
    max-width: var(--mrc-shell);
    padding-inline: var(--mrc-shell-pad);
    width: 100%;
}
.mrc-section {
    padding-bottom: clamp(54px, 8vw, 116px);
}
.mrc-hero {
    display: grid;
    gap: clamp(28px, 5vw, 80px);
    grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr);
    min-height: calc(100svh - 92px);
    padding-top: clamp(24px, 4vw, 64px);
}
.mrc-hero-copy {
    align-self: end;
    padding-bottom: clamp(24px, 5vw, 76px);
}
.mrc-hero-title {
    font-size: clamp(58px, 9.6vw, 148px);
    font-weight: 600;
    line-height: .86;
    margin: 0;
    max-width: 840px;
}
.mrc-content-page .mrc-hero {
    min-height: auto;
}
.mrc-content-page .mrc-hero-copy {
    padding-bottom: clamp(18px, 3vw, 44px);
}
.mrc-content-page .mrc-hero-title {
    font-size: clamp(56px, 7vw, 96px);
    line-height: .9;
}
.mrc-lead {
    color: var(--mrc-muted);
    font-size: clamp(17px, 1.5vw, 22px);
    line-height: 1.75;
    max-width: 680px;
}
.mrc-media-frame {
    background: var(--mrc-line);
    border-radius: var(--mrc-radius);
    min-height: 420px;
    overflow: hidden;
    position: relative;
}
.mrc-media-frame img {
    display: block;
    height: 100%;
    object-fit: cover;
    width: 100%;
}
.mrc-overlay-caption {
    background: linear-gradient(to top, rgba(27,46,41,.76), transparent);
    bottom: 0;
    color: var(--mrc-ivory);
    left: 0;
    padding: 28px;
    position: absolute;
    right: 0;
}
.mrc-hero-slider {
    background: var(--mrc-line);
    border-radius: var(--mrc-radius);
    min-height: 480px;
    overflow: hidden;
    position: relative;
}
.mrc-hero-slide {
    inset: 0;
    opacity: 0;
    position: absolute;
}
.mrc-hero-slide.is-active {
    opacity: 1;
}
.mrc-hero-slide img {
    display: block;
    height: 100%;
    object-fit: cover;
    width: 100%;
}
.mrc-hero-slide-caption {
    background: linear-gradient(to top, rgba(27,46,41,.74), transparent);
    bottom: 0;
    color: var(--mrc-ivory);
    left: 0;
    padding: clamp(22px, 4vw, 42px);
    position: absolute;
    right: 0;
}
.mrc-slider-dots {
    bottom: 18px;
    display: flex;
    gap: 8px;
    position: absolute;
    right: 18px;
    z-index: 2;
}
.mrc-slider-dot {
    background: rgba(255,250,242,.44);
    border: 0;
    border-radius: var(--mrc-pill);
    height: 8px;
    padding: 0;
    width: 28px;
}
.mrc-slider-dot.is-active {
    background: var(--mrc-cream);
}
.mrc-run-text {
    border-block: 1px solid var(--mrc-line);
    color: var(--mrc-ink);
    font-family: "Cormorant Garamond", Georgia, serif;
    font-size: clamp(42px, 7vw, 110px);
    font-weight: 600;
    line-height: .94;
    padding-block: clamp(32px, 5vw, 78px);
}
.mrc-editorial-grid {
    display: grid;
    gap: clamp(28px, 5vw, 78px);
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.mrc-editorial-grid > .mrc-wide {
    grid-column: span 2;
}
.mrc-card {
    background: var(--mrc-cream);
    border: 1px solid var(--mrc-line);
    border-radius: var(--mrc-radius);
    color: var(--mrc-ink);
    overflow: hidden;
    text-decoration: none;
}
.mrc-card-media {
    aspect-ratio: 4 / 5;
    background: var(--mrc-line);
    overflow: hidden;
}
.mrc-card,
.mrc-card *,
.mrc-product-card,
.mrc-product-card * {
    box-shadow: none !important;
    transform: none !important;
    transition: none !important;
}
.mrc-card-media img {
    height: 100%;
    object-fit: cover;
    width: 100%;
}
.mrc-card-body {
    display: grid;
    gap: 12px;
    padding: 18px;
}
.mrc-card-title {
    font-size: clamp(26px, 2.4vw, 40px);
    font-weight: 600;
    line-height: .98;
    margin: 0;
}
.mrc-link {
    color: var(--mrc-gold);
    display: inline-flex;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: .2em;
    position: relative;
    text-decoration: none;
    text-transform: uppercase;
}
.mrc-link:after {
    background: currentColor;
    bottom: -4px;
    content: "";
    height: 1px;
    left: 0;
    position: absolute;
    width: 100%;
}
.mrc-site-footer {
    background: var(--mrc-pine);
    color: var(--mrc-ivory);
    margin-top: clamp(44px, 8vw, 120px);
    max-width: none;
    padding-block: clamp(44px, 7vw, 96px);
    padding-inline: 0;
}
.mrc-footer-inner {
    box-sizing: border-box;
    display: grid;
    gap: 34px;
    grid-template-columns: minmax(0, 1.1fr) repeat(3, minmax(160px, .45fr));
    margin-inline: auto;
    max-width: var(--mrc-shell);
    padding-inline: var(--mrc-shell-pad);
    width: 100%;
}
.mrc-site-footer a {
    color: var(--mrc-ivory);
    text-decoration: none;
}
.mrc-footer-title {
    color: var(--mrc-gold);
    margin-bottom: 16px;
}
.mrc-footer-links {
    display: grid;
    gap: 10px;
    list-style: none;
    margin: 0;
    padding: 0;
}
.mrc-reveal {
    opacity: 1;
}
.mrc-reveal.is-visible {
    opacity: 1;
}
.mrc-luxury-theme button,
.mrc-luxury-theme input,
.mrc-luxury-theme select,
.mrc-luxury-theme textarea {
    border-radius: var(--mrc-pill) !important;
}
.mrc-luxury-theme textarea,
.mrc-luxury-theme .mrc-card,
.mrc-luxury-theme .mrc-mini-cart,
.mrc-luxury-theme .mrc-product-card,
.mrc-luxury-theme .mrc-wrap,
.mrc-luxury-theme .mrc-filter-panel,
.mrc-luxury-theme .mrc-status-card {
    border-radius: var(--mrc-radius) !important;
}
.mrc-luxury-theme img,
.mrc-luxury-theme .mrc-product-card-media,
.mrc-luxury-theme .mrc-product-card-placeholder {
    border-radius: var(--mrc-radius-sm);
}
.mrc-filter-panel {
    background: var(--mrc-cream);
    border: 1px solid var(--mrc-line);
    display: grid;
    gap: 18px;
    margin-bottom: 28px;
    padding: clamp(16px, 2.4vw, 28px);
}
.mrc-filter-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(5, minmax(0, 1fr));
}
.mrc-filter-field {
    display: grid;
    gap: 8px;
}
.mrc-filter-field label,
.mrc-filter-panel .mrc-filter-label {
    color: var(--mrc-gold);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .18em;
    text-transform: uppercase;
}
.mrc-filter-field input,
.mrc-filter-field select {
    background: #fbf6ed;
    border: 1px solid var(--mrc-line);
    color: var(--mrc-ink);
    min-height: 46px;
    padding: 0 14px;
    width: 100%;
}
.mrc-filter-actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.mrc-cart-button,
.mrc-icon-button {
    align-items: center;
    display: inline-flex !important;
    gap: 9px;
    justify-content: center;
    letter-spacing: .1em !important;
    padding-left: 14px !important;
    padding-right: 14px !important;
    white-space: nowrap;
}
.mrc-cart-button svg,
.mrc-icon-button svg {
    flex: 0 0 auto;
    height: 17px;
    stroke-width: 2;
    width: 17px;
}
.mrc-card-purchase-form {
    align-items: end;
    display: grid;
    gap: 12px;
    grid-template-columns: 74px minmax(0, 1fr);
    width: 100%;
}
.mrc-card-purchase-form label {
    display: grid !important;
    gap: 7px !important;
}
.mrc-card-purchase-form input[type="number"] {
    text-align: center;
    width: 100% !important;
}
.mrc-card-purchase-form .mrc-cart-button {
    min-width: 0;
    width: 100%;
}
.mrc-card-purchase-form .mrc-cart-button span {
    white-space: nowrap;
}
.mrc-card-secondary-link {
    width: 100%;
}
.mrc-feature-band {
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}
.mrc-feature-tile {
    background: var(--mrc-cream);
    border: 1px solid var(--mrc-line);
    border-radius: var(--mrc-radius);
    display: grid;
    gap: 10px;
    padding: clamp(18px, 2.6vw, 28px);
}
.mrc-page-band {
    border-block: 1px solid var(--mrc-line);
    display: grid;
    gap: clamp(24px, 4vw, 58px);
    grid-template-columns: minmax(0, .75fr) minmax(0, 1.25fr);
    padding-block: clamp(34px, 6vw, 82px);
}
.mrc-page-panel-grid {
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.mrc-page-panel {
    background: var(--mrc-cream);
    border: 1px solid var(--mrc-line);
    border-radius: var(--mrc-radius);
    display: grid;
    gap: 12px;
    padding: clamp(18px, 2.4vw, 30px);
}
.mrc-page-panel h2,
.mrc-page-panel h3 {
    margin: 0;
}
.mrc-page-panel p,
.mrc-page-band p {
    color: var(--mrc-muted);
    line-height: 1.7;
    margin: 0;
}
.mrc-page-metrics {
    display: grid;
    gap: 1px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}
.mrc-page-metric {
    background: var(--mrc-pine);
    color: var(--mrc-ivory);
    display: grid;
    gap: 8px;
    padding: clamp(18px, 2.4vw, 30px);
}
.mrc-page-metric strong {
    color: var(--mrc-cream);
    font-family: "Cormorant Garamond", Georgia, serif;
    font-size: clamp(34px, 4vw, 58px);
    font-weight: 600;
    line-height: .9;
}
.mrc-contact-layout {
    display: grid;
    gap: 18px;
    grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr);
}
.mrc-contact-card {
    background: var(--mrc-cream);
    border: 1px solid var(--mrc-line);
    border-radius: var(--mrc-radius);
    padding: clamp(18px, 2.8vw, 34px);
}
.mrc-contact-form {
    display: grid;
    gap: 14px;
}
.mrc-contact-form label {
    display: grid;
    gap: 8px;
}
.mrc-contact-form input,
.mrc-contact-form textarea {
    background: #fbf6ed;
    border: 1px solid var(--mrc-line);
    color: var(--mrc-ink);
    min-height: 46px;
    padding: 10px 14px;
}
.mrc-contact-form textarea {
    min-height: 132px;
    resize: vertical;
}
.mrc-timeline {
    counter-reset: step;
    display: grid;
    gap: 14px;
}
.mrc-timeline-item {
    align-items: start;
    background: var(--mrc-cream);
    border: 1px solid var(--mrc-line);
    border-radius: var(--mrc-radius);
    display: grid;
    gap: 16px;
    grid-template-columns: 44px minmax(0, 1fr);
    padding: 18px;
}
.mrc-timeline-item:before {
    align-items: center;
    background: var(--mrc-pine);
    border-radius: var(--mrc-radius);
    color: var(--mrc-ivory);
    content: counter(step, decimal-leading-zero);
    counter-increment: step;
    display: inline-flex;
    font-size: 11px;
    font-weight: 800;
    height: 44px;
    justify-content: center;
    letter-spacing: .12em;
    width: 44px;
}
.mrc-story-block {
    border-block: 1px solid var(--mrc-line);
    display: grid;
    gap: clamp(28px, 6vw, 92px);
    grid-template-columns: minmax(260px, .78fr) minmax(0, 1.22fr);
    padding-block: clamp(42px, 7vw, 108px);
}
.mrc-story-head {
    align-self: start;
}
.mrc-story-head h2,
.mrc-commerce-board h2,
.mrc-contact-console h2,
.mrc-page-cta h2 {
    font-size: clamp(38px, 4.9vw, 72px);
    font-weight: 600;
    line-height: .96;
    margin: 16px 0 0;
}
.mrc-story-body {
    display: grid;
    gap: clamp(28px, 4vw, 54px);
}
.mrc-story-body > p {
    color: var(--mrc-muted);
    font-size: clamp(18px, 1.55vw, 22px);
    line-height: 1.68;
    margin: 0;
    max-width: 820px;
}
.mrc-story-lines {
    border-block: 1px solid var(--mrc-line);
}
.mrc-story-lines article,
.mrc-route-row {
    border-bottom: 1px solid var(--mrc-line);
    display: grid;
    gap: 18px;
    grid-template-columns: minmax(180px, .82fr) minmax(0, 1.18fr);
    padding-block: clamp(20px, 3vw, 34px);
}
.mrc-story-lines article:last-child,
.mrc-route-row:last-child {
    border-bottom: 0;
}
.mrc-story-lines h3,
.mrc-route-row strong,
.mrc-board-card span {
    color: var(--mrc-ink);
    font-family: "Cormorant Garamond", Georgia, serif;
    font-size: clamp(26px, 2.35vw, 36px);
    font-weight: 600;
    line-height: .98;
    margin: 0;
}
.mrc-story-lines p,
.mrc-route-row p,
.mrc-board-card p,
.mrc-page-cta p {
    color: var(--mrc-muted);
    line-height: 1.75;
    margin: 0;
}
.mrc-commerce-board {
    background: var(--mrc-cream);
    border: 1px solid var(--mrc-line);
    border-radius: var(--mrc-radius);
    display: grid;
    gap: clamp(32px, 6vw, 82px);
    grid-template-columns: minmax(250px, .72fr) minmax(0, 1.28fr);
    padding: clamp(24px, 5vw, 68px);
}
.mrc-board-grid {
    display: grid;
    gap: 1px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.mrc-board-card {
    background: #fbf6ed;
    border: 1px solid var(--mrc-line);
    border-radius: var(--mrc-radius);
    display: grid;
    gap: 18px;
    min-height: 220px;
    padding: clamp(20px, 3vw, 34px);
}
.mrc-contact-console {
    background: var(--mrc-pine);
    border-radius: var(--mrc-radius);
    color: var(--mrc-ivory);
    display: grid;
    gap: clamp(28px, 5vw, 72px);
    grid-template-columns: minmax(0, 1fr) minmax(320px, .72fr);
    padding: clamp(24px, 5vw, 72px);
}
.mrc-contact-console .mrc-kicker,
.mrc-contact-console h2,
.mrc-contact-console .mrc-route-row strong {
    color: var(--mrc-cream);
}
.mrc-contact-console .mrc-route-row {
    border-color: rgba(236, 233, 228, .18);
}
.mrc-contact-console .mrc-route-row p {
    color: rgba(236, 233, 228, .68);
}
.mrc-route-list {
    border-block: 1px solid rgba(236, 233, 228, .18);
    margin-top: clamp(28px, 4vw, 54px);
}
.mrc-message-panel {
    align-self: start;
    background: var(--mrc-cream);
    border-radius: var(--mrc-radius);
    color: var(--mrc-ink);
    padding: clamp(20px, 3vw, 34px);
}
.mrc-message-panel h2 {
    font-size: clamp(34px, 3.2vw, 48px);
    margin-bottom: 24px;
}
.mrc-proof-strip {
    display: grid;
    gap: 1px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}
.mrc-proof-item {
    background: var(--mrc-pine);
    color: var(--mrc-ivory);
    display: grid;
    gap: 10px;
    min-height: 168px;
    padding: clamp(18px, 2.6vw, 34px);
}
.mrc-proof-item strong {
    color: var(--mrc-cream);
    font-family: "Cormorant Garamond", Georgia, serif;
    font-size: clamp(38px, 4vw, 58px);
    font-weight: 600;
    line-height: .86;
}
.mrc-page-cta {
    align-items: end;
    background: var(--mrc-rust);
    border-radius: var(--mrc-radius);
    color: var(--mrc-cream);
    display: grid;
    gap: clamp(24px, 4vw, 58px);
    grid-template-columns: minmax(0, .92fr) minmax(280px, .58fr);
    padding: clamp(24px, 5vw, 72px);
}
.mrc-page-cta .mrc-kicker,
.mrc-page-cta h2 {
    color: var(--mrc-cream);
}
.mrc-page-cta p {
    color: rgba(255, 250, 242, .72);
    font-size: clamp(17px, 1.6vw, 22px);
}
.mrc-page-cta-copy,
.mrc-page-cta-actions {
    display: grid;
    gap: clamp(18px, 3vw, 34px);
}
.mrc-page-cta-actions > div {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: flex-end;
}
.mrc-luxury-theme table th {
    color: var(--mrc-gold);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .18em;
    text-transform: uppercase;
}
.mrc-luxury-theme table td,
.mrc-luxury-theme table th {
    border-color: var(--mrc-line) !important;
}
@media (max-width: 900px) {
    .mrc-site-nav { display: none; }
    .mrc-menu-toggle { display: inline-flex; }
    .mrc-hero,
    .mrc-editorial-grid,
    .mrc-feature-band,
    .mrc-page-band,
    .mrc-page-panel-grid,
    .mrc-page-metrics,
    .mrc-story-block,
    .mrc-story-lines article,
    .mrc-commerce-board,
    .mrc-board-grid,
    .mrc-contact-layout,
    .mrc-contact-console,
    .mrc-route-row,
    .mrc-proof-strip,
    .mrc-page-cta,
    .mrc-footer-inner {
        grid-template-columns: 1fr;
    }
    .mrc-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .mrc-editorial-grid > .mrc-wide {
        grid-column: auto;
    }
    .mrc-hero {
        min-height: 0;
    }
    .mrc-page-cta-actions > div {
        justify-content: flex-start;
    }
}
@media (max-width: 560px) {
    .mrc-site-header-inner {
        padding-inline: 16px;
    }
    .mrc-section,
    .mrc-footer-inner {
        padding-inline: 16px;
    }
    .mrc-hero-title {
        font-size: clamp(54px, 16vw, 76px);
    }
    .mrc-media-frame {
        min-height: 280px;
    }
    .mrc-story-head h2,
    .mrc-commerce-board h2,
    .mrc-contact-console h2,
    .mrc-page-cta h2 {
        font-size: clamp(36px, 10.5vw, 54px);
    }
    .mrc-board-card,
    .mrc-proof-item {
        min-height: 0;
    }
    .mrc-filter-grid {
        grid-template-columns: 1fr;
    }
}
@media (prefers-reduced-motion: reduce) {
    .mrc-reveal,
    .mrc-hero-slide,
    .mrc-media-frame img,
    .mrc-card,
    .mrc-card img {
        transition: none !important;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var header = document.querySelector('[data-mrc-header]');
    var toggle = document.querySelector('[data-mrc-menu-toggle]');
    var panel = document.querySelector('[data-mrc-menu-panel]');
    var syncHeader = function () {
        if (!header) return;
        header.classList.toggle('is-scrolled', window.scrollY > 24);
    };
    syncHeader();
    window.addEventListener('scroll', syncHeader, { passive: true });
    if (toggle && panel && header) {
        toggle.addEventListener('click', function () {
            var open = !panel.classList.contains('is-open');
            panel.classList.toggle('is-open', open);
            header.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    var revealItems = document.querySelectorAll('.mrc-reveal');
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.16 });
        revealItems.forEach(function (item) { observer.observe(item); });
    } else {
        revealItems.forEach(function (item) { item.classList.add('is-visible'); });
    }
    document.querySelectorAll('[data-mrc-slider]').forEach(function (slider) {
        var slides = Array.prototype.slice.call(slider.querySelectorAll('[data-mrc-slide]'));
        var dots = Array.prototype.slice.call(slider.querySelectorAll('[data-mrc-dot]'));
        if (slides.length < 2) return;
        var current = 0;
        var show = function (index) {
            current = index % slides.length;
            slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === current); });
            dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === current); });
        };
        dots.forEach(function (dot, i) {
            dot.addEventListener('click', function () { show(i); });
        });
        window.setInterval(function () { show(current + 1); }, 5200);
    });
});
</script>
HTML;
    }

    function mrc_storefront_page_url($pages, $config, string $path): string {
        $page = $pages->get('/' . trim($path, '/') . '/');
        return ($page && $page->id) ? $page->url : rtrim((string) $config->urls->root, '/') . '/' . trim($path, '/') . '/';
    }

    function mrc_storefront_cart_icon(): string {
        return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"></circle><circle cx="19" cy="21" r="1"></circle><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h8.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path></svg>';
    }

    function mrc_storefront_collection_feature_product($pages, Page $collection): ?Page {
        $productName = match ((string) $collection->name) {
            'tableware' => 'stoneware-mug',
            'serveware' => 'terracotta-serving-bowl',
            'gifts' => 'ceramics-gift-card',
            'limited-stock' => 'dinnerware-starter-set',
            default => '',
        };
        if ($productName !== '') {
            $product = $pages->get('/products/' . $productName . '/');
            if ($product && $product->id && $product->hasField('mrc_images') && $product->mrc_images && $product->mrc_images->count()) {
                return $product;
            }
        }
        $fallback = $pages->get('template=mrc-product, mrc_collections=' . (int) $collection->id . ', mrc_images.count>0, sort=-created');
        return ($fallback && $fallback->id) ? $fallback : null;
    }

    function mrc_storefront_header(Mercato $commerce, $pages, $config, $sanitizer, string $active = ''): string {
        $cart = $commerce->cart();
        $cartQuantity = 0;
        foreach ($cart->values() as $item) {
            $cartQuantity += (int) ceil((float) ($item['quantity'] ?? 0));
        }
        $homeUrl = rtrim((string) $config->urls->root, '/') . '/';
        $productsUrl = mrc_storefront_page_url($pages, $config, 'products');
        $checkoutUrl = mrc_storefront_page_url($pages, $config, trim((string) ($commerce->cancel_page ?: 'checkout'), '/'));
        $collectionsUrl = mrc_storefront_page_url($pages, $config, 'collections');
        $aboutUrl = mrc_storefront_page_url($pages, $config, 'about-us');
        $contactUrl = mrc_storefront_page_url($pages, $config, 'contact-us');
        $collections = $pages->find('template=mrc-collection, parent=/collections/, sort=sort, sort=title, limit=6');
        $cartText = $cartQuantity > 0 ? 'Cart (' . $cartQuantity . ')' : 'Cart';
        $current = static fn(string $key): string => $active === $key ? ' aria-current="page"' : '';

        $out = '<header class="mrc-site-header" data-mrc-header><div class="mrc-site-header-inner">';
        $out .= '<a class="mrc-site-brand mrc-display" href="' . $sanitizer->entities($homeUrl) . '">Arlberg Ceramics</a>';
        $out .= '<nav class="mrc-site-nav mrc-small-caps" aria-label="Store navigation">';
        $out .= '<a href="' . $sanitizer->entities($productsUrl) . '"' . $current('products') . '>Shop</a>';
        if ($collections->count()) {
            $out .= '<a href="' . $sanitizer->entities($collectionsUrl) . '"' . $current('collections') . '>Collections</a>';
        }
        $out .= '<a href="' . $sanitizer->entities($aboutUrl) . '"' . $current('about') . '>About</a>';
        $out .= '<a href="' . $sanitizer->entities($contactUrl) . '"' . $current('contact') . '>Contact</a>';
        $out .= '<a href="' . $sanitizer->entities($checkoutUrl) . '"' . $current('checkout') . '>' . $sanitizer->entities($cartText) . '</a>';
        $out .= '</nav>';
        $out .= '<button class="mrc-menu-toggle mrc-small-caps" type="button" data-mrc-menu-toggle aria-expanded="false" aria-controls="mrc-store-menu"><span>Menu</span><span class="mrc-menu-lines" aria-hidden="true"><span></span><span></span></span></button>';
        $out .= '</div></header>';
        $out .= '<aside class="mrc-menu-panel" id="mrc-store-menu" data-mrc-menu-panel>';
        $out .= '<ul class="mrc-menu-list">';
        $out .= '<li><a href="' . $sanitizer->entities($productsUrl) . '">Shop</a></li>';
        foreach ($collections as $collection) {
            $out .= '<li><a href="' . $sanitizer->entities($collection->url) . '">' . $sanitizer->entities($collection->title) . '</a></li>';
        }
        $out .= '<li><a href="' . $sanitizer->entities($aboutUrl) . '">About</a></li>';
        $out .= '<li><a href="' . $sanitizer->entities($contactUrl) . '">Contact</a></li>';
        $out .= '<li><a href="' . $sanitizer->entities($checkoutUrl) . '">' . $sanitizer->entities($cartText) . '</a></li>';
        $out .= '</ul>';
        $out .= '</aside>';
        return $out;
    }

    function mrc_storefront_footer(Mercato $commerce, $pages, $config, $sanitizer): string {
        $productsUrl = mrc_storefront_page_url($pages, $config, 'products');
        $homeUrl = rtrim((string) $config->urls->root, '/') . '/';
        $checkoutUrl = mrc_storefront_page_url($pages, $config, trim((string) ($commerce->cancel_page ?: 'checkout'), '/'));
        $collectionsUrl = mrc_storefront_page_url($pages, $config, 'collections');
        $collections = $pages->find('template=mrc-collection, parent=/collections/, sort=sort, sort=title, limit=8');
        $policyPages = method_exists($commerce, 'getPolicyPages') ? $commerce->getPolicyPages() : new PageArray();
        $aboutUrl = mrc_storefront_page_url($pages, $config, 'about-us');
        $contactUrl = mrc_storefront_page_url($pages, $config, 'contact-us');
        $shippingUrl = mrc_storefront_page_url($pages, $config, 'shipping-and-returns');

        $out = '<footer class="mrc-site-footer">';
        $out .= '<div class="mrc-footer-inner">';
        $out .= '<div><a class="mrc-site-brand mrc-display" href="' . $sanitizer->entities($homeUrl) . '">Arlberg Ceramics</a>';
        $out .= '<p class="mrc-lead">A complete Mercato demo storefront for ceramic tableware, gifts, low-stock inventory, preorder products, discounts, checkout, delivery, pickup, and digital products.</p></div>';
        $out .= '<div><div class="mrc-footer-title mrc-small-caps">Shop</div><ul class="mrc-footer-links"><li><a href="' . $sanitizer->entities($productsUrl) . '">All products</a></li><li><a href="' . $sanitizer->entities($collectionsUrl) . '">Collections</a></li><li><a href="' . $sanitizer->entities($checkoutUrl) . '">Checkout</a></li></ul></div>';
        $out .= '<div><div class="mrc-footer-title mrc-small-caps">Collections</div><ul class="mrc-footer-links">';
        foreach ($collections as $collection) {
            $out .= '<li><a href="' . $sanitizer->entities($collection->url) . '">' . $sanitizer->entities($collection->title) . '</a></li>';
        }
        $out .= '</ul></div>';
        $out .= '<div><div class="mrc-footer-title mrc-small-caps">Studio</div><ul class="mrc-footer-links"><li><a href="' . $sanitizer->entities($aboutUrl) . '">About us</a></li><li><a href="' . $sanitizer->entities($contactUrl) . '">Contact us</a></li><li><a href="' . $sanitizer->entities($shippingUrl) . '">Shipping and returns</a></li></ul></div>';
        $out .= '<div><div class="mrc-footer-title mrc-small-caps">Policies</div><ul class="mrc-footer-links">';
        if ($policyPages->count()) {
            foreach ($policyPages as $policyPage) {
                $out .= '<li><a href="' . $sanitizer->entities($policyPage->url) . '">' . $sanitizer->entities($policyPage->title) . '</a></li>';
            }
        } else {
            $out .= '<li>Demo policies are generated on install.</li>';
        }
        $out .= '</ul></div>';
        $out .= '</div></footer>';
        return $out;
    }

    function mrc_storefront_filter_state($input, bool $includeCollection = true): array {
        $collection = $includeCollection ? max(0, (int) $input->get->int('collection')) : 0;
        $availability = (string) $input->get->text('availability');
        $sort = (string) $input->get->text('sort');
        $type = (string) $input->get->text('type');
        $allowedAvailability = ['', 'in-stock', 'low-stock', 'preorder', 'digital'];
        $allowedSort = ['', 'price-asc', 'price-desc', 'title', 'newest'];
        $allowedType = ['', 'physical', 'digital'];
        return [
            'collection' => $collection,
            'availability' => in_array($availability, $allowedAvailability, true) ? $availability : '',
            'sort' => in_array($sort, $allowedSort, true) ? $sort : '',
            'type' => in_array($type, $allowedType, true) ? $type : '',
            'min_price' => max(0.0, (float) $input->get->float('min_price')),
            'max_price' => max(0.0, (float) $input->get->float('max_price')),
        ];
    }

    function mrc_storefront_product_selector(array $state, ?Page $collection = null): string {
        $parts = ['template=mrc-product'];
        if ($collection && $collection->id) {
            $parts[] = 'mrc_collections=' . (int) $collection->id;
        } elseif (!empty($state['collection'])) {
            $parts[] = 'mrc_collections=' . (int) $state['collection'];
        }
        if ($state['type'] === 'physical' || $state['type'] === 'digital') {
            $parts[] = 'mrc_product_type=' . $state['type'];
        }
        if ($state['availability'] === 'in-stock') {
            $parts[] = 'mrc_stock>0';
        } elseif ($state['availability'] === 'low-stock') {
            $parts[] = 'mrc_stock>0';
            $parts[] = 'mrc_stock<=5';
        } elseif ($state['availability'] === 'preorder') {
            $parts[] = 'mrc_stock_policy=preorder';
        } elseif ($state['availability'] === 'digital') {
            $parts[] = 'mrc_product_type=digital';
        }
        if ($state['min_price'] > 0) {
            $parts[] = 'mrc_price>=' . number_format((float) $state['min_price'], 2, '.', '');
        }
        if ($state['max_price'] > 0) {
            $parts[] = 'mrc_price<=' . number_format((float) $state['max_price'], 2, '.', '');
        }
        $sort = match ($state['sort']) {
            'price-asc' => 'sort=mrc_price, sort=title',
            'price-desc' => 'sort=-mrc_price, sort=title',
            'title' => 'sort=title',
            'newest' => 'sort=-created',
            default => 'sort=sort, sort=title',
        };
        $parts[] = $sort;
        return implode(', ', $parts);
    }

    function mrc_storefront_filter_form(array $state, $collections, $sanitizer, string $action, bool $includeCollection = true): string {
        $e = static fn($value): string => $sanitizer->entities((string) $value);
        $selected = static fn($value, $current): string => (string) $value === (string) $current ? ' selected' : '';
        $out = '<form class="mrc-filter-panel" data-mrc-product-filters method="get" action="' . $e($action) . '">';
        $out .= '<div class="mrc-filter-grid">';
        if ($includeCollection) {
            $out .= '<div class="mrc-filter-field"><label for="mrc-filter-collection">Collection</label><select id="mrc-filter-collection" name="collection"><option value="">All collections</option>';
            foreach ($collections as $collection) {
                $out .= '<option value="' . (int) $collection->id . '"' . $selected((int) $collection->id, (int) $state['collection']) . '>' . $e($collection->title) . '</option>';
            }
            $out .= '</select></div>';
        }
        $out .= '<div class="mrc-filter-field"><label for="mrc-filter-availability">Availability</label><select id="mrc-filter-availability" name="availability">';
        foreach (['' => 'All stock', 'in-stock' => 'In stock', 'low-stock' => 'Low stock', 'preorder' => 'Preorder', 'digital' => 'Digital'] as $value => $label) {
            $out .= '<option value="' . $e($value) . '"' . $selected($value, $state['availability']) . '>' . $e($label) . '</option>';
        }
        $out .= '</select></div>';
        $out .= '<div class="mrc-filter-field"><label for="mrc-filter-type">Type</label><select id="mrc-filter-type" name="type">';
        foreach (['' => 'Any type', 'physical' => 'Physical', 'digital' => 'Digital'] as $value => $label) {
            $out .= '<option value="' . $e($value) . '"' . $selected($value, $state['type']) . '>' . $e($label) . '</option>';
        }
        $out .= '</select></div>';
        $out .= '<div class="mrc-filter-field"><label for="mrc-filter-min">Min price</label><input id="mrc-filter-min" name="min_price" inputmode="decimal" value="' . ($state['min_price'] > 0 ? $e($state['min_price']) : '') . '" placeholder="0"></div>';
        $out .= '<div class="mrc-filter-field"><label for="mrc-filter-max">Max price</label><input id="mrc-filter-max" name="max_price" inputmode="decimal" value="' . ($state['max_price'] > 0 ? $e($state['max_price']) : '') . '" placeholder="250"></div>';
        $out .= '<div class="mrc-filter-field"><label for="mrc-filter-sort">Sort</label><select id="mrc-filter-sort" name="sort">';
        foreach (['' => 'Featured', 'price-asc' => 'Price low', 'price-desc' => 'Price high', 'title' => 'Name', 'newest' => 'Newest'] as $value => $label) {
            $out .= '<option value="' . $e($value) . '"' . $selected($value, $state['sort']) . '>' . $e($label) . '</option>';
        }
        $out .= '</select></div></div>';
        $out .= '<div class="mrc-filter-actions"><button class="inline-flex min-h-11 items-center justify-center rounded-md bg-[#5b241f] px-6 text-xs font-semibold uppercase tracking-[0.18em] text-[#fffaf2]" type="submit">Apply filters</button><a class="inline-flex min-h-11 items-center justify-center rounded-md border border-[#5b241f] px-6 text-xs font-semibold uppercase tracking-[0.18em] text-[#5b241f] no-underline" href="' . $e($action) . '">Reset</a></div>';
        $out .= '</form>';
        return $out;
    }
}

<?php
namespace ProcessWire;

trait ProcessMercatoAdminStyles {

    protected function renderStyles(): string {
        $cssPath = dirname(__DIR__, 2) . '/assets/admin.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        $out = $css !== '' ? '<style>' . $css . '</style>' : '';
        $out .= '<script>
            document.addEventListener("click", function(event) {
                var button = event.target.closest ? event.target.closest(".mrc-copy-payment-link") : null;
                if (!button) return;
                var target = document.getElementById(button.getAttribute("data-copy-target") || "");
                if (!target) return;
                target.focus();
                target.select();
                var original = button.innerHTML;
                var done = function() {
                    button.textContent = "Copied";
                    window.setTimeout(function() { button.innerHTML = original; }, 1400);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(target.value).then(done).catch(function() {});
                } else if (document.execCommand && document.execCommand("copy")) {
                    done();
                }
            });
        </script>';
        return $out;
    }
}
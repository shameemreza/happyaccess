/**
 * HappyAccess Admin JavaScript
 *
 * @package HappyAccess
 * @since   1.0.0
 */

(function ($) {
  "use strict";

  $(document).ready(function () {
    // Handle form submission
    $("#happyaccess-generate-form").on("submit", function (e) {
      e.preventDefault();

      // Check GDPR consent
      if (!$("#happyaccess-gdpr-consent").is(":checked")) {
        alert(happyaccess_ajax.strings.gdpr_required);
        return false;
      }

      var formData = $(this).serialize();
      formData += "&action=happyaccess_generate_token";
      formData += "&nonce=" + happyaccess_ajax.nonce;

      // Include email_admin checkbox value
      if ($("#happyaccess-email-admin").is(":checked")) {
        formData += "&email_admin=1";
      }

      // Disable button
      $("#happyaccess-generate-btn")
        .prop("disabled", true)
        .text("Generating...");

      $.post(happyaccess_ajax.ajax_url, formData, function (response) {
        if (response.success) {
          // Show the OTP display section
          $("#happyaccess-otp-display").show();
          $("#happyaccess-otp-code").text(response.data.otp);
          $("#happyaccess-expires-display").text(response.data.expires);
          $("#happyaccess-role-display").text(response.data.role);
          $("#happyaccess-note-display").text(response.data.note || "-");

          // Scroll to the OTP display
          $("html, body").animate(
            {
              scrollTop: $("#happyaccess-otp-display").offset().top - 50,
            },
            500
          );
        } else {
          alert(response.data.message || "An error occurred");
        }

        // Re-enable button
        $("#happyaccess-generate-btn")
          .prop("disabled", false)
          .text("Generate Access Code");
      }).fail(function () {
        alert("Network error. Please try again.");
        $("#happyaccess-generate-btn")
          .prop("disabled", false)
          .text("Generate Access Code");
      });
    });

    // Copy OTP to clipboard
    $("#happyaccess-copy-otp").on("click", function () {
      var otp = $("#happyaccess-otp-code").text().trim();

      if (!otp) {
        return;
      }

      // Modern clipboard API (with fallback)
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard
          .writeText(otp)
          .then(function () {
            showCopySuccess();
          })
          .catch(function () {
            fallbackCopy(otp);
          });
      } else {
        fallbackCopy(otp);
      }
    });

    // Fallback copy method
    function fallbackCopy(text) {
      var $temp = $("<textarea>");
      $("body").append($temp);
      $temp.val(text).select();

      try {
        var successful = document.execCommand("copy");
        if (successful) {
          showCopySuccess();
        } else {
          alert(happyaccess_ajax.strings.copy_failed);
        }
      } catch (err) {
        alert(happyaccess_ajax.strings.copy_failed);
      }

      $temp.remove();
    }

    // Show copy success message
    function showCopySuccess() {
      var $button = $("#happyaccess-copy-otp");
      var originalText = $button.html();

      $button.html(
        '<span class="dashicons dashicons-yes" style="vertical-align: middle; color: #00a32a;"></span> ' +
          happyaccess_ajax.strings.copied
      );

      setTimeout(function () {
        $button.html(originalText);
      }, 2000);
    }

    // Handle token revocation
    $(".happyaccess-revoke-token").on("click", function (e) {
      e.preventDefault();

      if (!confirm(happyaccess_ajax.strings.confirm_revoke)) {
        return;
      }

      var $button = $(this);
      var tokenId = $button.data("token-id");

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_revoke_token",
          token_id: tokenId,
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          if (response.success) {
            $button.closest("tr").fadeOut(function () {
              $(this).remove();
            });
          } else {
            alert(response.data.message || "Revocation failed");
          }
        }
      );
    });

    // Handle logout all sessions
    $("#happyaccess-logout-sessions").on("click", function (e) {
      e.preventDefault();

      if (!confirm(happyaccess_ajax.strings.confirm_logout_sessions)) {
        return;
      }

      var $button = $(this);
      $button.prop("disabled", true).text(happyaccess_ajax.strings.logging_out);

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_logout_sessions",
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          if (response.success) {
            alert(response.data.message);
            location.reload();
          } else {
            alert(response.data.message || "Failed to logout sessions");
            $button
              .prop("disabled", false)
              .text(happyaccess_ajax.strings.logout_sessions);
          }
        }
      ).fail(function () {
        alert("Network error. Please try again.");
        $button
          .prop("disabled", false)
          .text(happyaccess_ajax.strings.logout_sessions);
      });
    });
  });
})(jQuery);

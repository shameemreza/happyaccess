/**
 * HappyAccess Admin JavaScript
 *
 * @package HappyAccess
 * @since   1.0.0
 */

(function ($) {
  "use strict";

  $(document).ready(function () {
    // Store token ID for share link generation
    var currentTokenId = null;
    var currentMagicLinkUrl = null;
    var currentMagicLinkExpires = null;

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

      // Include single_use checkbox value
      if ($("#happyaccess-single-use").is(":checked")) {
        formData += "&single_use=1";
      }

      // Include magic link options
      if ($("#happyaccess-generate-magic-link").is(":checked")) {
        formData += "&generate_magic_link=1";
        formData += "&magic_link_expiry=" + $("#magic_link_expiry").val();
      }

      // Include hide admin bar
      if ($("#happyaccess-hide-admin-bar").is(":checked")) {
        formData += "&hide_admin_bar=1";
      }

      // Include menu restrictions
      if ($("#happyaccess-enable-menu-restrictions").is(":checked")) {
        $('input[name="restricted_menus[]"]:checked').each(function () {
          formData +=
            "&restricted_menus[]=" + encodeURIComponent($(this).val());
        });
      }

      // Disable button
      $("#happyaccess-generate-btn")
        .prop("disabled", true)
        .text(happyaccess_ajax.strings.generating || "Generating...");

      $.post(happyaccess_ajax.ajax_url, formData, function (response) {
        if (response.success) {
          // Store token ID for share link generation
          currentTokenId = response.data.token_id;

          // Show the OTP display section
          $("#happyaccess-otp-display").show();
          $("#happyaccess-otp-code").text(response.data.otp);
          $("#happyaccess-expires-display").text(response.data.expires || "");
          $("#happyaccess-role-display").text(response.data.role || "");
          $("#happyaccess-note-display").text(response.data.note || "-");

          // Hide share link row (new generation)
          $("#happyaccess-share-link-row").hide();

          // Show single-use indicator if applicable
          if (response.data.single_use) {
            $("#happyaccess-single-use-display").html(
              '<strong style="color: #d63232;">' +
                (happyaccess_ajax.strings.one_time_use || "ONE-TIME USE") +
                "</strong> - " +
                (happyaccess_ajax.strings.one_time_use_desc || "Code will auto-revoke after first login")
            );
            $("#happyaccess-single-use-row").show();
          } else {
            $("#happyaccess-single-use-row").hide();
          }

          // Show magic link if generated
          if (response.data.magic_link) {
            currentMagicLinkUrl = response.data.magic_link.url;
            $("#happyaccess-magic-link-url").val(response.data.magic_link.url);
            $("#happyaccess-magic-link-expires").html(
              "<strong>" +
                (happyaccess_ajax.strings.magic_link_expires_at || "Expires:") +
                "</strong> " +
                response.data.magic_link.expires
            );
            $("#happyaccess-magic-link-row").show();
          } else {
            $("#happyaccess-magic-link-row").hide();
            currentMagicLinkUrl = null;
          }

          // Scroll to the OTP display
          $("html, body").animate(
            {
              scrollTop: $("#happyaccess-otp-display").offset().top - 50,
            },
            500
          );
        } else {
          alert(response.data.message || happyaccess_ajax.strings.error_generic);
        }

        // Re-enable button
        $("#happyaccess-generate-btn")
          .prop("disabled", false)
          .text(happyaccess_ajax.strings.generate_access_code);
      }).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $("#happyaccess-generate-btn")
          .prop("disabled", false)
          .text(happyaccess_ajax.strings.generate_access_code);
      });
    });

    // Copy OTP to clipboard
    $("#happyaccess-copy-otp").on("click", function () {
      var otp = $("#happyaccess-otp-code").text().trim();
      if (!otp) return;
      copyToClipboard(otp, $(this));
    });

    // Universal clipboard copy function
    function copyToClipboard(text, $button) {
      var originalText = $button.text();

      function showSuccess() {
        $button.text(happyaccess_ajax.strings.copied || "Copied!");
        setTimeout(function () {
          $button.text(originalText);
        }, 2000);
      }

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(showSuccess).catch(function () {
          fallbackCopy(text, showSuccess);
        });
      } else {
        fallbackCopy(text, showSuccess);
      }
    }

    function fallbackCopy(text, callback) {
      var $temp = $("<textarea>");
      $("body").append($temp);
      $temp.val(text).select();
      try {
        document.execCommand("copy");
        if (callback) callback();
      } catch (err) {
        alert(happyaccess_ajax.strings.copy_failed || "Copy failed");
      }
      $temp.remove();
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
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
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
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
            $button
              .prop("disabled", false)
              .text(happyaccess_ajax.strings.logout_sessions);
          }
        }
      ).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $button
          .prop("disabled", false)
          .text(happyaccess_ajax.strings.logout_sessions);
      });
    });

    // Handle clear all logs
    $("#happyaccess-clear-logs").on("click", function (e) {
      e.preventDefault();

      if (!confirm(happyaccess_ajax.strings.confirm_clear_logs)) {
        return;
      }

      var $button = $(this);
      $button.prop("disabled", true).text(happyaccess_ajax.strings.clearing_logs);

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_clear_logs",
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          if (response.success) {
            alert(response.data.message);
            location.reload();
          } else {
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
            $button
              .prop("disabled", false)
              .text(happyaccess_ajax.strings.clear_all_logs);
          }
        }
      ).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $button
          .prop("disabled", false)
          .text(happyaccess_ajax.strings.clear_all_logs);
      });
    });

    // Toggle magic link options visibility
    $("#happyaccess-generate-magic-link").on("change", function () {
      if ($(this).is(":checked")) {
        $("#happyaccess-magic-link-options").slideDown();
      } else {
        $("#happyaccess-magic-link-options").slideUp();
      }
    });

    // Copy magic link to clipboard
    $("#happyaccess-copy-magic-link").on("click", function () {
      var magicLink = $("#happyaccess-magic-link-url").val().trim();
      if (!magicLink) return;
      $("#happyaccess-magic-link-url").select();
      copyToClipboard(magicLink, $(this));
    });

    // Handle share OTP link generation - NO PROMPT, uses settings default
    $("#happyaccess-share-otp").on("click", function (e) {
      e.preventDefault();

      var otpCode = $("#happyaccess-otp-code").text().trim();
      if (!otpCode || !currentTokenId) {
        alert(happyaccess_ajax.strings.error_generic);
        return;
      }

      var $button = $(this);
      var originalText = $button.text();
      $button
        .prop("disabled", true)
        .text(happyaccess_ajax.strings.generating || "Generating...");

      // Use settings default (in seconds)
      var expiration = happyaccess_ajax.settings.share_link_expiry || 300;

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_generate_share_link",
          token_id: currentTokenId,
          otp_code: otpCode,
          expiration: expiration,
          single_view: "1",
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          if (response.success) {
            $("#happyaccess-share-link-url").val(response.data.url);
            var info =
              "<strong>" +
              (happyaccess_ajax.strings.share_link_expires || "Expires:") +
              "</strong> " +
              response.data.expires;
            if (response.data.single_view) {
              info +=
                " &bull; <em>" +
                (happyaccess_ajax.strings.share_link_single_view ||
                  "Single view") +
                "</em>";
            }
            $("#happyaccess-share-link-info").html(info);
            $("#happyaccess-share-link-row").slideDown();

            // Auto-copy to clipboard
            if (navigator.clipboard && window.isSecureContext) {
              navigator.clipboard.writeText(response.data.url);
            }
          } else {
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
          }
          $button.prop("disabled", false).text(originalText);
        }
      ).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $button.prop("disabled", false).text(originalText);
      });
    });

    // Copy share link to clipboard
    $("#happyaccess-copy-share-link").on("click", function () {
      var shareLink = $("#happyaccess-share-link-url").val().trim();
      if (!shareLink) return;
      $("#happyaccess-share-link-url").select();
      copyToClipboard(shareLink, $(this));
    });

    // Handle email magic link - Opens WordPress modal
    $("#happyaccess-email-magic-link").on("click", function (e) {
      e.preventDefault();

      var magicLinkUrl = $("#happyaccess-magic-link-url").val().trim();
      if (!magicLinkUrl) {
        alert(happyaccess_ajax.strings.error_generic);
        return;
      }

      currentMagicLinkUrl = magicLinkUrl;
      $("#happyaccess-email-recipient").val("");

      // Open Thickbox modal
      tb_show(
        happyaccess_ajax.strings.send_email || "Send Magic Link",
        "#TB_inline?width=400&height=200&inlineId=happyaccess-email-modal"
      );
    });

    // Handle send email button in modal
    $("#happyaccess-send-email-btn").on("click", function () {
      var recipient = $("#happyaccess-email-recipient").val().trim();

      // Basic email validation
      if (!recipient || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(recipient)) {
        alert(
          happyaccess_ajax.strings.email_error ||
            "Please enter a valid email address."
        );
        return;
      }

      var $button = $(this);
      var originalText = $button.text();
      $button
        .prop("disabled", true)
        .text(happyaccess_ajax.strings.sending || "Sending...");

      // Get expires from stored value or from visible element
      var expires = currentMagicLinkExpires || "";
      if (!expires) {
        expires = $("#happyaccess-magic-link-expires").text();
        expires = expires
          .replace(
            happyaccess_ajax.strings.magic_link_expires_at || "Expires:",
            ""
          )
          .trim();
      }

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_email_magic_link",
          magic_link_url: currentMagicLinkUrl,
          expires: expires,
          recipient: recipient,
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          $button.prop("disabled", false).text(originalText);
          if (response.success) {
            // Close the modal first
            try {
              tb_remove();
            } catch (e) {
              // Fallback: hide thickbox manually
              $("#TB_window, #TB_overlay").remove();
              $("body").removeClass("modal-open");
            }
          } else {
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
          }
        }
      ).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $button.prop("disabled", false).text(originalText);
      });
    });

    // Handle magic link generation from Active Tokens - NO PROMPT, uses settings default
    $(".happyaccess-magic-link").on("click", function (e) {
      e.preventDefault();

      var $button = $(this);
      var tokenId = $button.data("token-id");
      var originalText = $button.text();

      $button
        .prop("disabled", true)
        .text(happyaccess_ajax.strings.generating || "Generating...");

      // Use settings default (in seconds)
      var expiration = happyaccess_ajax.settings.magic_link_expiry || 300;

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_generate_magic_link",
          token_id: tokenId,
          expiration: expiration,
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          if (response.success) {
            // Store for email modal use
            currentMagicLinkUrl = response.data.url;
            currentMagicLinkExpires = response.data.expires;

            // Show in WordPress modal
            $("#happyaccess-modal-link").val(response.data.url);
            $("#happyaccess-modal-expires").html(
              "<strong>" +
                (happyaccess_ajax.strings.magic_link_expires_at || "Expires:") +
                "</strong> " +
                response.data.expires
            );

            tb_show(
              happyaccess_ajax.strings.magic_link_title || "Magic Link",
              "#TB_inline?width=500&height=200&inlineId=happyaccess-link-modal"
            );

            // Auto-copy to clipboard
            if (navigator.clipboard && window.isSecureContext) {
              navigator.clipboard.writeText(response.data.url);
            }
          } else {
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
          }

          $button.prop("disabled", false).text(originalText);
        }
      ).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $button.prop("disabled", false).text(originalText);
      });
    });

    // Copy link from modal
    $("#happyaccess-modal-copy-btn").on("click", function () {
      var link = $("#happyaccess-modal-link").val().trim();
      if (!link) return;
      $("#happyaccess-modal-link").select();

      var $button = $(this);
      var originalText = $button.text();

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(link).then(function () {
          $button.text(happyaccess_ajax.strings.copied || "Copied!");
          setTimeout(function () {
            $button.text(originalText);
          }, 2000);
        });
      } else {
        fallbackCopy(link, function () {
          $button.text(happyaccess_ajax.strings.copied || "Copied!");
          setTimeout(function () {
            $button.text(originalText);
          }, 2000);
        });
      }
    });

    // Deactivate temp user
    $(document).on("click", ".happyaccess-deactivate-user", function (e) {
      e.preventDefault();
      if (!confirm(happyaccess_ajax.strings.confirm_deactivate)) return;

      var $button = $(this);
      var userId = $button.data("user-id");
      var originalText = $button.text();
      $button
        .prop("disabled", true)
        .text(happyaccess_ajax.strings.deactivating || "Deactivating...");

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_deactivate_user",
          user_id: userId,
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          if (response.success) {
            location.reload();
          } else {
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
            $button.prop("disabled", false).text(originalText);
          }
        }
      ).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $button.prop("disabled", false).text(originalText);
      });
    });

    // Reactivate temp user
    $(document).on("click", ".happyaccess-reactivate-user", function (e) {
      e.preventDefault();
      if (!confirm(happyaccess_ajax.strings.confirm_reactivate)) return;

      var $button = $(this);
      var userId = $button.data("user-id");
      var originalText = $button.text();
      $button
        .prop("disabled", true)
        .text(happyaccess_ajax.strings.reactivating || "Reactivating...");

      $.post(
        happyaccess_ajax.ajax_url,
        {
          action: "happyaccess_reactivate_user",
          user_id: userId,
          nonce: happyaccess_ajax.nonce,
        },
        function (response) {
          if (response.success) {
            location.reload();
          } else {
            alert(response.data.message || happyaccess_ajax.strings.error_generic);
            $button.prop("disabled", false).text(originalText);
          }
        }
      ).fail(function () {
        alert(happyaccess_ajax.strings.network_error);
        $button.prop("disabled", false).text(originalText);
      });
    });

    // Toggle menu restrictions picker
    $("#happyaccess-enable-menu-restrictions").on("change", function () {
      var $picker = $("#happyaccess-menu-restrictions-picker");
      if ($(this).is(":checked")) {
        $picker.slideDown();
        // Build menu items from pre-loaded data (only once)
        if (
          $("#happyaccess-menu-items-list").children(".happyaccess-menu-group")
            .length === 0
        ) {
          var items = happyaccess_ajax.menu_items || [];
          if (items.length) {
            var html = "";
            $.each(items, function (i, item) {
              var esc = function (s) {
                return $("<div>").text(s).html();
              };
              var hasChildren =
                item.children && item.children.length > 0;

              html += '<div class="happyaccess-menu-group" style="margin-bottom: 6px;">';
              html +=
                '<label style="display:block; margin: 4px 0; cursor: pointer; font-weight: 600;">' +
                '<input type="checkbox" class="happyaccess-parent-menu" name="restricted_menus[]" value="' +
                esc(item.slug) +
                '"> ' +
                esc(item.title) +
                ' <code style="font-size: 11px; color: #888; font-weight: normal;">' +
                esc(item.slug) +
                "</code></label>";

              if (hasChildren) {
                html +=
                  '<div class="happyaccess-submenu-list" style="margin-left: 24px; border-left: 2px solid #ddd; padding-left: 10px;">';
                $.each(item.children, function (j, child) {
                  html +=
                    '<label style="display:block; margin: 2px 0; cursor: pointer;">' +
                    '<input type="checkbox" class="happyaccess-sub-menu" name="restricted_menus[]" value="' +
                    esc(child.slug) +
                    '" data-parent="' +
                    esc(item.slug) +
                    '"> ' +
                    esc(child.title) +
                    ' <code style="font-size: 10px; color: #999;">' +
                    esc(child.raw) +
                    "</code></label>";
                });
                html += "</div>";
              }
              html += "</div>";
            });
            $("#happyaccess-menu-items-list").html(html);

            // When a parent menu is checked, auto-check all its submenus
            $("#happyaccess-menu-items-list").on(
              "change",
              ".happyaccess-parent-menu",
              function () {
                var isChecked = $(this).is(":checked");
                $(this)
                  .closest(".happyaccess-menu-group")
                  .find(".happyaccess-sub-menu")
                  .prop("checked", isChecked);
              }
            );
          } else {
            $("#happyaccess-menu-items-list").html(
              "<p><em>No menu items available.</em></p>"
            );
          }
        }
      } else {
        $picker.slideUp();
      }
    });

    // Email link from magic link modal
    $("#happyaccess-modal-email-btn").on("click", function () {
      var link = $("#happyaccess-modal-link").val().trim();
      if (!link) return;

      // Store the link for email modal
      currentMagicLinkUrl = link;

      // Close current modal and open email modal
      try {
        tb_remove();
      } catch (e) {
        $("#TB_window, #TB_overlay").remove();
      }

      // Clear email input and open email modal
      $("#happyaccess-email-recipient").val("");

      setTimeout(function () {
        tb_show(
          happyaccess_ajax.strings.send_email || "Send Magic Link",
          "#TB_inline?width=400&height=200&inlineId=happyaccess-email-modal"
        );
      }, 100);
    });
  });
})(jQuery);

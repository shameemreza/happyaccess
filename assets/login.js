/**
 * HappyAccess Login JavaScript
 *
 * @package HappyAccess
 * @since   1.0.0
 */

document.addEventListener("DOMContentLoaded", function () {
  "use strict";

  var otpField = document.getElementById("happyaccess_otp");
  var loginForm = document.getElementById("loginform");
  var userLogin = document.getElementById("user_login");
  var userPass = document.getElementById("user_pass");

  if (!otpField || !loginForm) {
    return;
  }

  // Handle OTP input
  otpField.addEventListener("input", function () {
    var otpValue = this.value.trim();

    if (otpValue.length > 0) {
      // Remove required attribute from username and password fields
      if (userLogin) {
        userLogin.removeAttribute("required");
        userLogin.setAttribute("data-was-required", "true");
      }
      if (userPass) {
        userPass.removeAttribute("required");
        userPass.setAttribute("data-was-required", "true");
      }
    } else {
      // Restore required attributes if OTP is cleared
      if (userLogin && userLogin.getAttribute("data-was-required") === "true") {
        userLogin.setAttribute("required", "required");
      }
      if (userPass && userPass.getAttribute("data-was-required") === "true") {
        userPass.setAttribute("required", "required");
      }
    }
  });

  // Handle form submission
  loginForm.addEventListener("submit", function (e) {
    var otpValue = otpField.value.trim();

    // If OTP is provided, bypass normal validation
    if (otpValue.length === 6) {
      // Remove required attributes to allow submission
      if (userLogin) {
        userLogin.removeAttribute("required");
      }
      if (userPass) {
        userPass.removeAttribute("required");
      }

      // Optionally clear username/password to avoid confusion
      if (userLogin && !userLogin.value) {
        userLogin.value = "";
      }
      if (userPass && !userPass.value) {
        userPass.value = "";
      }
    }
  });

  // Auto-focus OTP field if coming from HappyAccess
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get("happyaccess") === "1" && otpField) {
    otpField.focus();
  }
});

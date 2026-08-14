<?php
include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/login.css">

<div class="login-page">

    <div class="login-left">

        <div class="brand">
            <h1>AGAP</h1>

            <p>
                Automated Grievance Assistance Platform
            </p>
        </div>

        <div class="features">
            <div>✓ Complaint Management</div>
            <div>✓ Case Monitoring</div>
            <div>✓ Hearing Scheduling</div>
            <div>✓ GPS Verification</div>
            <div>✓ AI Complaint Assistant</div>
        </div>

    </div>

    <div class="login-right">

        <form
            action="../../../backend/api/auth/login.php"
            method="POST"
            class="login-card">

            <h2>Welcome Back</h2>

            <p>
                Sign in to continue
            </p>

            <div class="form-group">

                <label for="username">
                    Username
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    required>

            </div>

            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    required>

            </div>

            <button type="submit">
                Sign In
            </button>

            <p class="auth-link"><a href="forgot-password.php">Forgot your password?</a></p>

        </form>

    </div>

</div>

<script src="../../assets/js/login.js"></script>

<?php
include '../../layouts/footer.php';
?>

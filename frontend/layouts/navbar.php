<nav class="navbar">

    <div class="navbar-container">

        <div class="navbar-brand">
            AGAP
        </div>

        <div class="navbar-right">

            <span class="username">
                <?php echo $_SESSION['username'] ?? 'User'; ?>
            </span>

            <a
                href="../../../backend/api/auth/logout.php"
                class="logout-btn">

                Logout

            </a>

        </div>

    </div>

</nav>
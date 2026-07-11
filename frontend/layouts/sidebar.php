<aside class="sidebar">

    <ul class="sidebar-menu">

        <li>
            <a href="<?php

                switch ($_SESSION['role_id']) {

                    case 1:
                        echo '../dashboard/admin-dashboard.php';
                        break;

                    case 2:
                        echo '../dashboard/clerk-dashboard.php';
                        break;

                    case 3:
                        echo '../dashboard/lupon-dashboard.php';
                        break;

                    case 4:
                        echo '../dashboard/server-dashboard.php';
                        break;
                }

            ?>">
                Dashboard
            </a>
        </li>

        <?php if (
            $_SESSION['role_id'] == 1 ||
            $_SESSION['role_id'] == 2
        ): ?>

            <li>
                <a href="../residents/resident-list.php">
                    Residents
                </a>
            </li>

            <li>
                <a href="../complaints/complaint-list.php">
                    Complaints
                </a>
            </li>

            <li>
                <a href="../cases/case-list.php">
                    Cases
                </a>
            </li>

            <li>
                <a href="../search/records.php">
                    Records Search
                </a>
            </li>

            <li>
                <a href="../hearings/schedules.php">
                    Hearings
                </a>
            </li>

            <li>
                <a href="../pangkat/pangkat-list.php">
                    Pangkat
                </a>
            </li>

            <li>
                <a href="../documents/document-center.php">
                    KP Documents
                </a>
            </li>

            <li>
                <a href="../reports/report-list.php">
                    Reports
                </a>
            </li>

        <?php endif; ?>

        <?php if ($_SESSION['role_id'] == 1): ?>
            <li>
                <a href="../users/user-list.php">
                    Users
                </a>
            </li>
        <?php endif; ?>


        <?php if ($_SESSION['role_id'] == 3): ?>

            <li>
                <a href="../hearings/schedules.php">
                    Hearings
                </a>
            </li>

            <li>
                <a href="../pangkat/pangkat-list.php">
                    Pangkat
                </a>
            </li>

        <?php endif; ?>


        <?php if ($_SESSION['role_id'] == 4): ?>

            <li>
                <a href="../gps/proof-service.php">
                    Proof of Service
                </a>
            </li>

            <li>
                <a href="../gps/incident-map.php">
                    GPS Tracking
                </a>
            </li>

        <?php endif; ?>

    </ul>

</aside>

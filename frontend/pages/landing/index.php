<?php include '../../layouts/header.php'; ?>

<link
    rel="stylesheet"
    href="/AGAP/frontend/assets/css/landing.css">

<!-- Navbar -->

<nav
    class="
    bg-black
    text-white
    p-5">

    <div
        class="
        max-w-7xl
        mx-auto
        flex
        justify-between
        items-center">

        <h1
            class="
            text-2xl
            font-bold">

            AGAP

        </h1>

        <a
            href="../auth/login.php"
            class="
            bg-white
            text-black
            px-5
            py-2
            rounded-lg
            font-semibold">

            Login

        </a>

    </div>

</nav>

<!-- Hero -->

<section
    class="
    hero-section
    bg-black
    text-white
    flex
    items-center
    justify-center">

    <div
        class="
        text-center
        max-w-3xl
        px-8">

        <h1
            class="
            text-6xl
            font-bold
            mb-6">

            AGAP

        </h1>

        <p
            class="
            text-2xl
            mb-4">

            Automated Grievance
            Assistance Platform

        </p>

        <p
            class="
            text-lg
            mb-10">

            Streamlining Katarungang
            Pambarangay Case Management,
            Hearing Scheduling,
            Documentation,
            and Conflict Resolution.

        </p>

        <a
            href="../auth/login.php"
            class="
            bg-white
            text-black
            px-8
            py-4
            rounded-lg
            font-bold">

            Get Started

        </a>

    </div>

</section>

<!-- Features -->

<section
    class="
    py-20
    bg-white">

    <div
        class="
        max-w-7xl
        mx-auto
        px-6">

        <h2
            class="
            text-center
            text-4xl
            font-bold
            mb-12">

            Core Features

        </h2>

        <div
            class="
            grid
            md:grid-cols-3
            gap-6">

            <div
                class="
                feature-card
                bg-gray-50
                p-6
                rounded-xl
                shadow">

                <h3
                    class="
                    text-xl
                    font-bold
                    mb-3">

                    Complaint Management

                </h3>

                <p>

                    Digitized complaint filing,
                    tracking, and monitoring.

                </p>

            </div>

            <div
                class="
                feature-card
                bg-gray-50
                p-6
                rounded-xl
                shadow">

                <h3
                    class="
                    text-xl
                    font-bold
                    mb-3">

                    Hearing Scheduling

                </h3>

                <p>

                    Automated scheduling,
                    mediation, and conciliation
                    management.

                </p>

            </div>

            <div
                class="
                feature-card
                bg-gray-50
                p-6
                rounded-xl
                shadow">

                <h3
                    class="
                    text-xl
                    font-bold
                    mb-3">

                    Document Generation

                </h3>

                <p>

                    Generate KP Forms,
                    Summons,
                    Notices,
                    and Reports.

                </p>

            </div>

            <div
                class="
                feature-card
                bg-gray-50
                p-6
                rounded-xl
                shadow">

                <h3
                    class="
                    text-xl
                    font-bold
                    mb-3">

                    GPS Monitoring

                </h3>

                <p>

                    Pin incidents and verify
                    proof of service.

                </p>

            </div>

            <div
                class="
                feature-card
                bg-gray-50
                p-6
                rounded-xl
                shadow">

                <h3
                    class="
                    text-xl
                    font-bold
                    mb-3">

                    AI Assistant

                </h3>

                <p>

                    Complaint assistance and
                    narrative generation
                    through AI.

                </p>

            </div>

            <div
                class="
                feature-card
                bg-gray-50
                p-6
                rounded-xl
                shadow">

                <h3
                    class="
                    text-xl
                    font-bold
                    mb-3">

                    Reporting

                </h3>

                <p>

                    Monthly,
                    Quarterly,
                    Annual,
                    and DILG reports.

                </p>

            </div>

        </div>

    </div>

</section>

<!-- About -->

<section
    class="
    bg-gray-100
    py-20">

    <div
        class="
        max-w-5xl
        mx-auto
        text-center
        px-6">

        <h2
            class="
            text-4xl
            font-bold
            mb-6">

            About AGAP

        </h2>

        <p
            class="
            text-lg
            leading-relaxed">

            AGAP is a digital grievance
            management system designed to
            modernize Katarungang
            Pambarangay operations through
            centralized records management,
            scheduling, monitoring,
            reporting, AI assistance,
            and GPS-enabled field services.

        </p>

    </div>

</section>

<!-- Footer -->

<footer
    class="
    bg-black
    text-white
    text-center
    p-6">

    <p>

        AGAP © <?php echo date('Y'); ?>

    </p>

</footer>

<script src="/AGAP/frontend/assets/js/landing.js"></script>

<?php include '../../layouts/footer.php'; ?>

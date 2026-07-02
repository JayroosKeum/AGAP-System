<!DOCTYPE html>
<html>
<head>
    <title>KP Form 9</title>

    <style>

        body{
            font-family: Arial;
            margin:40px;
        }

        .print-btn{
            margin-bottom:20px;
        }

        @media print{
            .print-btn{
                display:none;
            }
        }

    </style>

</head>
<body>

<button
    onclick="window.print()"
    class="print-btn">
    Print / Save PDF
</button>

<h2>KP FORM 9</h2>

<h3>SUMMONS</h3>

<p>Case Number:
<?= $_GET['case_number'] ?? '' ?>
</p>

<p>
You are hereby ordered to appear
before the Lupong Tagapamayapa
for mediation proceedings.
</p>

</body>
</html>

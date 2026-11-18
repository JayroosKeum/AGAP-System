<script src="../../assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/app.js'); ?>"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        var swUrl = <?php echo json_encode(file_exists(__DIR__ . '/../../public/sw.js') ? '../../../public/sw.js' : '/sw.js'); ?>;
        navigator.serviceWorker.register(swUrl).catch(function () {});
    });
}
</script>

</body>
</html>

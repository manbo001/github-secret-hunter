    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var html = document.documentElement;
        var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        try { localStorage.setItem('sm-theme', next); } catch (e) {}
    });
})();
</script>
</body>
</html>

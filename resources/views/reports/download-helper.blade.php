@once
<script>
window.downloadVieFundReport = (url) => {
    const frame = document.createElement('iframe');
    frame.hidden = true;
    frame.src = url;
    document.body.appendChild(frame);
    window.setTimeout(() => frame.remove(), 60000);
};
</script>
@endonce

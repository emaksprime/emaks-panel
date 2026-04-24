fetch('/api/health')
  .then(res => res.json())
  .then(data => {
    document.getElementById('status').innerText =
      "Çalışıyor ✅ (" + data.time + ")";
  })
  .catch(() => {
    document.getElementById('status').innerText =
      "HATA ❌";
  });

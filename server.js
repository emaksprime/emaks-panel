const express = require('express');
const app = express();

app.get('/', (req, res) => {
  res.send('Emaks Panel Çalışıyor 🚀');
});

app.listen(8080, () => {
  console.log('Server running on 8080');
});

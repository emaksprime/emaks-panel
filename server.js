const express = require('express');
const app = express();

const PORT = process.env.PORT || 8080;

app.get('/', (req, res) => {
  res.send('Emaks Panel Çalışıyor 🚀');
});

app.listen(PORT, '0.0.0.0', () => {
  console.log('Server running on ' + PORT);
});

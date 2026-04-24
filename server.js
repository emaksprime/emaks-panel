const express = require('express');
const app = express();

const PORT = process.env.PORT || 8080;

function home(req, res) {
  res.send('Emaks Panel Çalışıyor 🚀');
}

app.get('/', home);
app.get('/dashboard', home);
app.get('/dashboard/', home);

app.listen(PORT, '0.0.0.0', () => {
  console.log('Server running on ' + PORT);
});

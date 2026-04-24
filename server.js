const express = require('express');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 8080;

// static frontend
app.use(express.static(path.join(__dirname, 'public')));

// API
app.get('/api/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'emaks-panel',
    time: new Date()
  });
});

// fallback → index.html
app.get('*', (req, res) => {
  res.sendFile(path.join(__dirname, 'public/index.html'));
});

app.listen(PORT, '0.0.0.0', () => {
  console.log('Server running on ' + PORT);
});

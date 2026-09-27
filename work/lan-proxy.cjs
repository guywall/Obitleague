#!/usr/bin/env node
/**
 * Obitleague LAN proxy.
 *
 * Local (LocalWP) binds the site router to 127.0.0.1:80 and only answers
 * when the Host header is "obitleague.local". Devices on the LAN cannot
 * resolve or send that hostname, so this proxy listens on 0.0.0.0:8080 and
 * forwards every request to 127.0.0.1:80 with the Host header rewritten.
 * WordPress side, wp-config.php reads X-Obit-Host so page URLs match the
 * address the visitor typed (e.g. http://192.168.68.99:8080/).
 * (X-Forwarded-Host is unsuitable: Local's router rewrites it to the
 * upstream Host value, so we use a custom header it passes through.)
 *
 * Usage:  node work/lan-proxy.cjs   (run detached; logs to work/lan-proxy.*.log)
 */
'use strict';

const http = require('http');

const LISTEN_PORT = 8080;
const UPSTREAM_HOST = '127.0.0.1';
const UPSTREAM_PORT = 80;
const SITE_HOSTNAME = 'obitleague.local';

const server = http.createServer((req, res) => {
  const headers = { ...req.headers };
  headers.host = SITE_HOSTNAME;
  headers['x-obit-host'] = req.headers.host || '';

  const upstream = http.request(
    {
      host: UPSTREAM_HOST,
      port: UPSTREAM_PORT,
      path: req.url,
      method: req.method,
      headers,
    },
    (upRes) => {
      res.writeHead(upRes.statusCode || 502, upRes.headers);
      upRes.pipe(res);
    }
  );

  upstream.on('error', (err) => {
    res.writeHead(502, { 'content-type': 'text/plain' });
    res.end('Obitleague LAN proxy: upstream error: ' + err.message + '\n');
  });

  req.pipe(upstream);
});

server.on('error', (err) => {
  if (err.code === 'EADDRINUSE') {
    console.error('Port ' + LISTEN_PORT + ' is already in use - is the proxy already running?');
  } else {
    console.error('Proxy error: ' + err.message);
  }
  process.exit(1);
});

server.listen(LISTEN_PORT, '0.0.0.0', () => {
  console.log('Obitleague LAN proxy listening on 0.0.0.0:' + LISTEN_PORT + ' -> ' + UPSTREAM_HOST + ':' + UPSTREAM_PORT + ' (Host: ' + SITE_HOSTNAME + ')');
});

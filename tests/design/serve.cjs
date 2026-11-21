// Match the local Caddy deployment's gzip delivery in the disposable browser lab.
const http = require('node:http');
const zlib = require('node:zlib');
http.createServer((req, res) => {
    const remote = http.request({hostname:'127.0.0.1',port:8001,path:req.url,method:req.method,headers:req.headers}, upstream => {
        const headers = {...upstream.headers};
        const compress = /\bgzip\b/.test(req.headers['accept-encoding'] || '') &&
            /text\/|javascript|json|svg/.test(headers['content-type'] || '') && !headers['content-encoding'];
        if (compress) {
            delete headers['content-length'];
            headers['content-encoding']='gzip';
            headers.vary='Accept-Encoding';
        }
        res.writeHead(upstream.statusCode,headers);
        if (compress) upstream.pipe(zlib.createGzip()).pipe(res);
        else upstream.pipe(res);
    });
    remote.on('error',()=>{res.writeHead(502);res.end();});
    req.pipe(remote);
}).listen(8000,'127.0.0.1');

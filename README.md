# XrayR for V2Board
为V2Board自动安装XrayR

## 使用说明
 - 将php文件应放到V2Board目录/app/Http/Controllers/Server 下
 - 如果网站有CDN请确保Web服务器能正常获取来访客户端IP
 
## 一键对接
 - 请先安装curl
 - `bash <(curl -sSL "你的V2Board地址/api/v1/server/Deepbwork/install?token=你设置的Token")`
 - 执行后会自动安装XrayR并在V2Ray服务器表内自动插入新的服务器信息

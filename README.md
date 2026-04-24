# 简易访问量计数器

<p align="center">
  <img src="https://visitor.serveryyswys.top/cnt/github-simple-visitorcnt"></img><br>
  <strong>简单易用，适合个人使用的简易访问量计数器</strong><br><br>
</p>

## 特点
|功能|描述|
|-|-|
|计数器|只需在任何支持Markdown的地方插入链接，即可记录访问次数|
|管理面板|提供便捷的管理方法，2fa+cookie；高隐蔽性，非授权访问一律404|
|轻量级|200多行代码|
|||

## 部署
需要安装PHP的GD库，用于渲染图片

## 准备
你需要先配置一条伪静态，以nginx为例：
```nginx
location /cnt/ {
    rewrite ^/cnt/(.*)$ /index.php?path=$1 last;
}
```
对于管理面板，你可以加一层防护，配置BasicAuth或使用WAF或使用fail2ban等

## 配置
### 管理面板
#### 首次使用
访问管理面板，一般为：`http://www.example.com/manager.php`   
随后，你会看到2fa的配置界面，使用Google Authenticator或类似软件扫描二维码（或输入代码），用户名随意   
然后，你就进入管理面板主界面了
#### 登录
登录面板你需要访问`http://www.example.com/manager.php?check=xxxxxx`，其中`xxxxxx`填TOTP一次性密码    
如果在管理面板使用任意功能后提示cookie无效，则可能是cookie过期，或有新的设备完成了登录
### Tag
Tag是左下角图片，你可以使用各种Tag以在不同场景下使用计数器   
Tag需要你将图片文件上传到站点根目录，随后请在管理面板上的相关字段处填写完整的文件名（如`test.png`）   
Tag不宜太大，推荐长100像素，宽40像素的png格式的图片
### 友好说明
该字段是计数器名称，如果你没填写该字段，默认以路由为名称
### 功能隐藏
你可以通过下面的操作隐藏管理面板的存在：
 - 上传其他开源项目的favicon.ico（如兰空图床）

## 使用
在任意支持Markdown的平台，插入`![](http://www.example.com/cnt/你的路由名称)`即可

## 资源
该项目使用了一个外部资源：思源黑体

## 说明
该项目由我提出（包括架构），代码由AI设计

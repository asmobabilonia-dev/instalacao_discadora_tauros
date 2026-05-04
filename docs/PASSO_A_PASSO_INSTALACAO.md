# Passo a Passo da Instalacao

## 1. Preparar a VPS

```bash
ssh root@IP_DA_VPS
apt update
apt upgrade -y
apt install -y git
```

## 2. Apontar dominio

No Cloudflare:

1. Abra a zona do dominio.
2. Crie um registro `A`.
3. Nome: `painel` ou `@`.
4. Conteudo: IP da VPS.
5. Aguarde propagar.

Para emitir SSL com Certbot, se houver erro, deixe o proxy como **DNS Only** temporariamente.

## 3. Baixar o instalador

```bash
git clone https://github.com/asmobabilonia-dev/instalacao_discadora_tauros.git
cd instalacao_discadora_tauros
```

## 4. Rodar instalador

```bash
sudo bash install_discadora_tauros.sh
```

Responda os campos no terminal.

## 5. Conferir servicos

```bash
systemctl status nginx
systemctl status mariadb
systemctl status php*-fpm
```

## 6. Testar site

Abra:

```text
https://SEU_DOMINIO
```

## 7. Se SSL falhar

```bash
certbot --nginx -d SEU_DOMINIO
```

Depois:

```bash
systemctl reload nginx
```

## 8. Logs

```bash
tail -f /var/log/nginx/tauros-discadora.error.log
tail -f /var/log/nginx/tauros-discadora.access.log
```

## 9. Conferir PHP

```bash
php -v
php -m | grep -E 'pdo_mysql|curl|mbstring|openssl'
```

## 10. Conferir banco

```bash
mysql -u tauros_user -p tauros_discadora
SHOW TABLES;
```


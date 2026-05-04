# Instalacao Discadora Tauros

Repositorio instalavel da Discadora Tauros para VPS Linux.

O instalador e interativo: ele pergunta dominio, e-mail do SSL, usuario admin, senha, banco, Asterisk, AMI e Magnus durante a instalacao. Assim voce nao precisa editar arquivos manualmente antes de comecar.

## Requisitos

- VPS Ubuntu 22.04/24.04 ou Debian 12.
- Acesso `root` ou `sudo`.
- Dominio apontado para o IP da VPS.
- Cloudflare pode estar apontando o DNS. Para emitir SSL pelo Certbot, se falhar com proxy laranja, deixe o registro como **DNS Only** temporariamente.

## Instalacao rapida

```bash
sudo apt update
sudo apt install -y git
git clone https://github.com/asmobabilonia-dev/instalacao_discadora_tauros.git
cd instalacao_discadora_tauros
sudo bash install_discadora_tauros.sh
```

O script vai perguntar:

- dominio do painel;
- e-mail para certificado SSL;
- nome do sistema;
- nome, e-mail e senha do primeiro admin;
- nome do banco MariaDB;
- usuario e senha do banco;
- IP/host do Asterisk;
- dados AMI;
- IP/host do Magnus;
- se deve emitir SSL;
- se deve configurar firewall.

## O que o instalador faz

- Instala Nginx.
- Instala PHP-FPM e extensoes PHP.
- Instala MariaDB.
- Cria banco e usuario.
- Copia a aplicacao para `/var/www/tauros-discadora`.
- Gera `config/config.php`.
- Roda migrations.
- Cria/atualiza o primeiro admin.
- Configura Nginx.
- Emite SSL com Certbot, se solicitado.
- Libera firewall para SSH, HTTP e HTTPS.

## Depois de instalar

1. Acesse `https://SEU_DOMINIO`.
2. Entre com o admin criado no instalador.
3. Abra **Configuracoes**.
4. Complete Asterisk/AMI/Magnus, se necessario.
5. Sincronize ramais, filas e URAs.
6. Teste login, campanha pequena e transferencia para atendente.

## Atualizar

```bash
cd instalacao_discadora_tauros
git pull
sudo bash install_discadora_tauros.sh
```

O instalador recria os arquivos da aplicacao, preservando `config/config.php`, `data/` e `uploads/`.

## Segurança

Nao commite:

- `app/config/config.php`
- `app/data/*`
- `app/uploads/*`

Esses caminhos ja estao no `.gitignore`.


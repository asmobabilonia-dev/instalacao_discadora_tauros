# Template Asterisk para Discadora SIP

Este pacote instala e prepara um servidor Asterisk para trabalhar com a Discadora SIP.

Ele instala dependencias, configura WebRTC, AMI, filas, contexto `from-webrtc`, contexto `from-agents`, tronco `magnus` configuravel e arquivos `#include` usados pela sincronizacao de ramais do painel.

## Instalar em um servidor novo

```bash
sudo apt update
sudo apt install -y git
git clone SEU_REPOSITORIO_GITHUB discadora-asterisk
cd discadora-asterisk/deploy/asterisk-template
cp .env.example .env
nano .env
sudo bash install.sh
```

Se preferir, rode sem `.env`; o instalador pergunta os campos obrigatorios.

## Depois da instalacao

No painel da Discadora, configure:

- Servidor Asterisk: IP ou dominio do servidor novo.
- Chave SSH do servidor Asterisk.
- Fila padrao: o mesmo `DEFAULT_QUEUE_NAME`.
- Numero da fila padrao: o mesmo `DEFAULT_QUEUE_EXTEN`.
- SIP/WebRTC: `wss://SEU_DOMINIO:8089/ws` ou `ws://IP:8088/ws`.
- AMI: usuario e senha definidos em `AMI_USER` e `AMI_SECRET`.

## Arquivos gerados

- `/etc/asterisk/pjsip_discadora_base.conf`
- `/etc/asterisk/extensions_discadora_base.conf`
- `/etc/asterisk/queues_discadora_base.conf`
- `/etc/asterisk/manager_discadora.conf`
- `/etc/asterisk/pjsip_codex_agents.conf`
- `/etc/asterisk/extensions_codex_agents.conf`
- `/etc/asterisk/queues_codex_discadora.conf`

Os arquivos `*_codex_*` sao os que o painel pode sobrescrever quando sincroniza ramais e filas. Os arquivos `*_base.conf` guardam a base do servidor.

## Segurança

Nao suba `.env` para o GitHub. Ele contem IPs e senhas do ambiente.

O template deixa o Magnus configuravel por variaveis. Assim voce pode reinstalar outro Asterisk sem alterar codigo da discadora.

#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${ROOT_DIR}/.env"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Execute como root: sudo bash install.sh"
  exit 1
fi

if [[ -f "${ENV_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${ENV_FILE}"
fi

ask() {
  local var="$1"
  local label="$2"
  local default="${3:-}"
  local secret="${4:-0}"
  local current="${!var:-}"
  if [[ -n "${current}" ]]; then
    return
  fi
  if [[ "${secret}" == "1" ]]; then
    read -r -s -p "${label}${default:+ [${default}]}: " current
    echo
  else
    read -r -p "${label}${default:+ [${default}]}: " current
  fi
  if [[ -z "${current}" ]]; then
    current="${default}"
  fi
  printf -v "${var}" '%s' "${current}"
}

detect_public_ip() {
  curl -fsS --max-time 4 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}'
}

ASTERISK_PUBLIC_IP="${ASTERISK_PUBLIC_IP:-$(detect_public_ip || true)}"
WEBRTC_WS_PORT="${WEBRTC_WS_PORT:-8088}"
WEBRTC_WSS_PORT="${WEBRTC_WSS_PORT:-8089}"
RTP_START="${RTP_START:-10000}"
RTP_END="${RTP_END:-20000}"
DEFAULT_QUEUE_NAME="${DEFAULT_QUEUE_NAME:-discadora}"
DEFAULT_QUEUE_EXTEN="${DEFAULT_QUEUE_EXTEN:-700}"
AMI_USER="${AMI_USER:-discadora}"
AMI_PERMIT="${AMI_PERMIT:-0.0.0.0/0.0.0.0}"
MAGNUS_SIP_PORT="${MAGNUS_SIP_PORT:-5060}"
INSTALL_TURN="${INSTALL_TURN:-1}"
TURN_PORT="${TURN_PORT:-3478}"
TURN_USER="${TURN_USER:-discadora}"
CONFIGURE_UFW="${CONFIGURE_UFW:-1}"

ask ASTERISK_PUBLIC_IP "IP publico do Asterisk" "${ASTERISK_PUBLIC_IP}"
ask ASTERISK_DOMAIN "Dominio do Asterisk, vazio para usar IP" ""
ask AMI_SECRET "Senha AMI da discadora" "" 1
ask MAGNUS_SIP_HOST "IP/dominio SIP do MagnusBilling" ""
ask MAGNUS_USERNAME "Usuario SIP/tronco Magnus" ""
ask MAGNUS_AUTH_USER "Auth user Magnus, vazio para usar o usuario" "${MAGNUS_USERNAME}"
ask MAGNUS_PASSWORD "Senha SIP/tronco Magnus" "" 1
ask MAGNUS_FROM_DOMAIN "From domain Magnus, vazio para usar host Magnus" "${MAGNUS_SIP_HOST}"

if [[ "${INSTALL_TURN}" == "1" ]]; then
  TURN_REALM="${TURN_REALM:-${ASTERISK_DOMAIN:-${ASTERISK_PUBLIC_IP}}}"
  ask TURN_PASSWORD "Senha TURN" "" 1
fi

MAGNUS_AUTH_USER="${MAGNUS_AUTH_USER:-${MAGNUS_USERNAME}}"
MAGNUS_FROM_DOMAIN="${MAGNUS_FROM_DOMAIN:-${MAGNUS_SIP_HOST}}"
ASTERISK_HOSTNAME="${ASTERISK_DOMAIN:-${ASTERISK_PUBLIC_IP}}"

install_packages() {
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y asterisk coturn openssl curl ca-certificates ufw
    DEBIAN_FRONTEND=noninteractive apt-get install -y asterisk-opus || true
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y epel-release || true
    dnf install -y asterisk coturn openssl curl firewalld
  elif command -v yum >/dev/null 2>&1; then
    yum install -y epel-release || true
    yum install -y asterisk coturn openssl curl firewalld
  else
    echo "Gerenciador de pacotes nao suportado. Instale Asterisk, coturn, openssl e curl manualmente."
    exit 1
  fi
}

backup_asterisk() {
  local stamp
  stamp="$(date +%Y%m%d-%H%M%S)"
  mkdir -p "/root/discadora-asterisk-backups"
  if [[ -d /etc/asterisk ]]; then
    tar -czf "/root/discadora-asterisk-backups/etc-asterisk-${stamp}.tar.gz" /etc/asterisk
  fi
}

ensure_include() {
  local file="$1"
  local include="$2"
  touch "${file}"
  grep -qxF "#include ${include}" "${file}" || printf '\n#include %s\n' "${include}" >> "${file}"
}

write_configs() {
  install -d -m 0750 -o asterisk -g asterisk /etc/asterisk/keys
  if [[ ! -f /etc/asterisk/keys/asterisk.key || ! -f /etc/asterisk/keys/asterisk.pem ]]; then
    openssl req -x509 -newkey rsa:2048 -nodes \
      -keyout /etc/asterisk/keys/asterisk.key \
      -out /etc/asterisk/keys/asterisk.pem \
      -days 3650 \
      -subj "/CN=${ASTERISK_HOSTNAME}"
    chown asterisk:asterisk /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.pem
    chmod 0640 /etc/asterisk/keys/asterisk.key
  fi

  cat >/etc/asterisk/http_discadora.conf <<EOF_HTTP
; Gerado pelo instalador Discadora SIP.
[general]
enabled=yes
bindaddr=0.0.0.0
bindport=${WEBRTC_WS_PORT}
tlsenable=yes
tlsbindaddr=0.0.0.0:${WEBRTC_WSS_PORT}
tlscertfile=/etc/asterisk/keys/asterisk.pem
tlsprivatekey=/etc/asterisk/keys/asterisk.key
EOF_HTTP

  cat >/etc/asterisk/rtp_discadora.conf <<EOF_RTP
; Gerado pelo instalador Discadora SIP.
[general]
rtpstart=${RTP_START}
rtpend=${RTP_END}
icesupport=yes
stunaddr=stun.l.google.com:19302
strictrtp=no
EOF_RTP

  cat >/etc/asterisk/manager_discadora.conf <<EOF_MANAGER
; Gerado pelo instalador Discadora SIP.
[${AMI_USER}]
secret=${AMI_SECRET}
read=system,call,log,verbose,command,agent,user,originate,reporting
write=system,call,log,verbose,command,agent,user,originate,reporting
permit=${AMI_PERMIT}
EOF_MANAGER

  cat >/etc/asterisk/pjsip_discadora_base.conf <<EOF_PJSIP
; Gerado pelo instalador Discadora SIP.

[global]
type=global
user_agent=DiscadoraSIP-Asterisk

[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
external_media_address=${ASTERISK_PUBLIC_IP}
external_signaling_address=${ASTERISK_PUBLIC_IP}
local_net=10.0.0.0/8
local_net=172.16.0.0/12
local_net=192.168.0.0/16

[transport-ws]
type=transport
protocol=ws
bind=0.0.0.0
external_media_address=${ASTERISK_PUBLIC_IP}
external_signaling_address=${ASTERISK_PUBLIC_IP}
local_net=10.0.0.0/8
local_net=172.16.0.0/12
local_net=192.168.0.0/16

[transport-wss]
type=transport
protocol=wss
bind=0.0.0.0
cert_file=/etc/asterisk/keys/asterisk.pem
priv_key_file=/etc/asterisk/keys/asterisk.key
external_media_address=${ASTERISK_PUBLIC_IP}
external_signaling_address=${ASTERISK_PUBLIC_IP}
local_net=10.0.0.0/8
local_net=172.16.0.0/12
local_net=192.168.0.0/16

[magnus]
type=endpoint
transport=transport-udp
context=from-magnus
disallow=all
allow=ulaw,alaw
outbound_auth=magnus
aors=magnus
from_domain=${MAGNUS_FROM_DOMAIN}
from_user=${MAGNUS_USERNAME}
direct_media=no
force_rport=yes
rewrite_contact=yes
rtp_symmetric=yes
dtmf_mode=rfc4733

[magnus]
type=auth
auth_type=userpass
username=${MAGNUS_AUTH_USER}
password=${MAGNUS_PASSWORD}

[magnus]
type=aor
contact=sip:${MAGNUS_SIP_HOST}:${MAGNUS_SIP_PORT}
qualify_frequency=30

[magnus-identify]
type=identify
endpoint=magnus
match=${MAGNUS_SIP_HOST}
EOF_PJSIP

  cat >/etc/asterisk/queues_discadora_base.conf <<EOF_QUEUES
; Gerado pelo instalador Discadora SIP.
[${DEFAULT_QUEUE_NAME}]
musicclass=default
strategy=linear
timeout=25
retry=1
ringinuse=no
joinempty=no
leavewhenempty=yes
announce-frequency=0
timeoutrestart=no
autofill=yes
EOF_QUEUES

  cat >/etc/asterisk/extensions_discadora_base.conf <<EOF_EXT
; Gerado pelo instalador Discadora SIP.

[set-outbound-callerid]
exten => s,1,NoOp(Ajustando callerid para saida)
 same => n,ExecIf(\$["\${ARG1}" != ""]?Set(CALLERID(num)=\${ARG1}))
 same => n,ExecIf(\$["\${ARG1}" != ""]?Set(CALLERID(name)=\${ARG1}))
 same => n,Return()

[from-webrtc]
exten => ${DEFAULT_QUEUE_EXTEN},1,NoOp(Entrada na fila padrao ${DEFAULT_QUEUE_NAME})
 same => n,Answer()
 same => n,Ringing()
 same => n,Queue(${DEFAULT_QUEUE_NAME},trn,,,30)
 same => n,Hangup()

exten => _X.,1,NoOp(Saida externa para \${EXTEN} via Magnus)
 same => n,Dial(PJSIP/\${EXTEN}@magnus,60,rtTb(set-outbound-callerid^s^1(\${CALLERID(num)})))
 same => n,Hangup()

[from-agents]
exten => ${DEFAULT_QUEUE_EXTEN},1,NoOp(Entrada na fila padrao ${DEFAULT_QUEUE_NAME})
 same => n,Answer()
 same => n,Ringing()
 same => n,Queue(${DEFAULT_QUEUE_NAME},trn,,,30)
 same => n,Hangup()

exten => _X.,1,Goto(from-webrtc,\${EXTEN},1)

[from-magnus]
exten => _X.,1,NoOp(Entrada do Magnus: \${EXTEN})
 same => n,Hangup()
EOF_EXT

  touch /etc/asterisk/pjsip_codex_agents.conf /etc/asterisk/extensions_codex_agents.conf /etc/asterisk/queues_codex_discadora.conf
  chown asterisk:asterisk /etc/asterisk/*_discadora*.conf /etc/asterisk/*_codex_*.conf /etc/asterisk/http_discadora.conf /etc/asterisk/rtp_discadora.conf /etc/asterisk/manager_discadora.conf
  chmod 0640 /etc/asterisk/*_discadora*.conf /etc/asterisk/*_codex_*.conf /etc/asterisk/http_discadora.conf /etc/asterisk/rtp_discadora.conf /etc/asterisk/manager_discadora.conf

  ensure_include /etc/asterisk/http.conf http_discadora.conf
  ensure_include /etc/asterisk/rtp.conf rtp_discadora.conf
  ensure_include /etc/asterisk/manager.conf manager_discadora.conf
  ensure_include /etc/asterisk/pjsip.conf pjsip_discadora_base.conf
  ensure_include /etc/asterisk/pjsip.conf pjsip_codex_agents.conf
  ensure_include /etc/asterisk/queues.conf queues_discadora_base.conf
  ensure_include /etc/asterisk/queues.conf queues_codex_discadora.conf
  ensure_include /etc/asterisk/extensions.conf extensions_discadora_base.conf
  ensure_include /etc/asterisk/extensions.conf extensions_codex_agents.conf
}

write_turn() {
  if [[ "${INSTALL_TURN}" != "1" ]]; then
    return
  fi
  cat >/etc/turnserver.conf <<EOF_TURN
listening-port=${TURN_PORT}
fingerprint
lt-cred-mech
user=${TURN_USER}:${TURN_PASSWORD}
realm=${TURN_REALM}
external-ip=${ASTERISK_PUBLIC_IP}
no-multicast-peers
no-cli
log-file=/var/log/turnserver.log
simple-log
EOF_TURN
  if [[ -f /etc/default/coturn ]]; then
    sed -i 's/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
  fi
}

configure_firewall() {
  if [[ "${CONFIGURE_UFW}" != "1" ]] || ! command -v ufw >/dev/null 2>&1; then
    return
  fi
  ufw allow 22/tcp || true
  ufw allow 5060/udp || true
  ufw allow "${WEBRTC_WS_PORT}/tcp" || true
  ufw allow "${WEBRTC_WSS_PORT}/tcp" || true
  ufw allow "${RTP_START}:${RTP_END}/udp" || true
  if [[ "${INSTALL_TURN}" == "1" ]]; then
    ufw allow "${TURN_PORT}/udp" || true
    ufw allow "${TURN_PORT}/tcp" || true
  fi
}

restart_services() {
  systemctl enable asterisk || true
  systemctl restart asterisk
  if [[ "${INSTALL_TURN}" == "1" ]]; then
    systemctl enable coturn || true
    systemctl restart coturn || true
  fi
  asterisk -rx 'module reload res_http_websocket.so' || true
  asterisk -rx 'pjsip reload' || true
  asterisk -rx 'queue reload all' || true
  asterisk -rx 'dialplan reload' || true
}

print_summary() {
  cat <<EOF_SUMMARY

Instalacao finalizada.

WebSocket SIP:
  ws://${ASTERISK_HOSTNAME}:${WEBRTC_WS_PORT}/ws
  wss://${ASTERISK_HOSTNAME}:${WEBRTC_WSS_PORT}/ws

AMI:
  usuario: ${AMI_USER}
  porta: 5038

Fila padrao:
  nome: ${DEFAULT_QUEUE_NAME}
  ramal: ${DEFAULT_QUEUE_EXTEN}

Tronco Magnus:
  endpoint PJSIP: magnus
  host: ${MAGNUS_SIP_HOST}:${MAGNUS_SIP_PORT}

No painel da Discadora, configure o servidor Asterisk com este IP/dominio e rode a sincronizacao dos ramais.
EOF_SUMMARY
}

install_packages
backup_asterisk
write_configs
write_turn
configure_firewall
restart_services
print_summary

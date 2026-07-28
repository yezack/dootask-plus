<template>
    <div class="token-transfer">
        <Loading/>
    </div>
</template>

<style lang="scss" scoped>
.token-transfer {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
</style>
<script>
export default {
    mounted() {
        this.goNext1();
    },

    methods: {
        decodeParam(value) {
            try {
                return decodeURIComponent(value || '')
            } catch (_) {
                return ''
            }
        },

        safeFrom(value) {
            if (typeof value !== 'string' || !value) {
                return ''
            }
            if (!value.startsWith('/') && !/^https?:\/\//i.test(value)) {
                try {
                    value = decodeURIComponent(value)
                } catch (_) {
                    return ''
                }
            }
            if (/[\u0000-\u001f\u007f\\]/.test(value) || /^\/\//.test(value)) {
                return ''
            }
            try {
                const url = new URL(value, window.location.origin)
                if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password) {
                    return ''
                }
                return `${url.pathname}${url.search}${url.hash}`
            } catch (_) {
                return ''
            }
        },

        clearCredentials() {
            const url = new URL(window.location.href)
            url.searchParams.delete('ticket')
            url.searchParams.delete('token')
            const hashIndex = url.hash.indexOf('?')
            if (hashIndex !== -1) {
                const hashPath = url.hash.substring(0, hashIndex)
                const hashParams = new URLSearchParams(url.hash.substring(hashIndex + 1))
                hashParams.delete('ticket')
                hashParams.delete('token')
                const hashQuery = hashParams.toString()
                url.hash = hashPath + (hashQuery ? `?${hashQuery}` : '')
            }
            window.history.replaceState(null, '', url.pathname + url.search + url.hash)
        },

        async goNext1() {
            const params = $A.urlParameterAll();
            const ticket = this.decodeParam(params.ticket)
            const token = this.decodeParam(params.token)
            try {
                if (ticket) {
                    const {data} = await this.$store.dispatch("call", {
                        url: 'uniauth/exchange',
                        method: 'post',
                        data: {ticket}
                    })
                    await this.$store.dispatch("handleClearCache", data)
                } else if (token) {
                    const {data} = await this.$store.dispatch("call", {
                        url: 'users/info',
                        header: {token}
                    })
                    await this.$store.dispatch("saveUserInfo", data)
                } else {
                    this.goForward({name: 'login'}, true)
                    return
                }
                this.clearCredentials()
                this.goNext2()
            } catch (_) {
                this.clearCredentials()
                $A.messageError(this.$L('登录失败，请稍后重试。'))
                this.goForward({name: 'login'}, true)
            }
        },

        goNext2() {
            const fromUrl = this.safeFrom(this.$route.query.from)
            if (fromUrl) {
                window.location.replace(fromUrl);
            } else {
                this.goForward({name: 'manage-dashboard'}, true);
            }
        }
    }
}
</script>

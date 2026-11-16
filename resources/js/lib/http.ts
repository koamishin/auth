import axios from 'axios';

const http = axios.create({
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
    },
});

const token =
    typeof document !== 'undefined'
        ? document
              .querySelector('meta[name="csrf-token"]')
              ?.getAttribute('content')
        : null;
if (token) {
    http.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

export default http;

(() => {
            const bg = document.querySelector('.parallax-bg');
            if (!bg) return;
            const speed = 0.3;
            let ticking = false;
            const update = () => {
                const y = window.pageYOffset * speed;
                bg.style.backgroundPosition = 'center ' + (-y) + 'px';
                ticking = false;
            };
            window.addEventListener('scroll', () => {
                if (ticking) return;
                window.requestAnimationFrame(update);
                ticking = true;
            });
            update();
        })();

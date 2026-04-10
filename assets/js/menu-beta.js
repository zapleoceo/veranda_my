(() => {
    const bg = document.querySelector('.parallax-bg');
    if (!bg) return;
    const bgUrl = bg.getAttribute('data-bg');
    if (bgUrl) bg.style.backgroundImage = `url('${bgUrl}')`;
    const speed = 0.3;
    let ticking = false;
    const update = () => {
        const y = window.pageYOffset;
        
        // When we scroll down (y > 0), moving the background up (negative px)
        // can reveal the bottom of the background image if we scroll too far.
        // By using calc and forcing the background-size to be larger, we prevent this.
        bg.style.transform = `translate3d(0, ${-y * speed}px, 0) scale(1.18)`;
        
        ticking = false;
    };
    window.addEventListener('scroll', () => {
        if (ticking) return;
        window.requestAnimationFrame(update);
        ticking = true;
    });
    update();
})();

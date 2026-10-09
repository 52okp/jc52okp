Page({ back() { if (getCurrentPages().length > 1) wx.navigateBack(); else wx.switchTab({ url: '/pages/home/home' }); } });
